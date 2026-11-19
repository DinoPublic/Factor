/*
    02_correccion.sql — Corrige la facturación de septiembre de 2026

    Todo en una transacción e idempotente: si alguna comprobación final falla, no se aplica nada.

    Ejecución:
    docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' -C -I -f 65001 -d facturacion -b < scripts/02_correccion.sql
*/
SET NOCOUNT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;

-- Cálculo correcto de una factura: consumo del mes y precio repartido por días. También lo usa 03_prevencion.sql.
-- Se crea con EXEC para que todo el script sea un único bloque (sin GO).
EXEC (N'
CREATE OR ALTER FUNCTION dbo.fn_calcular_factura (@contrato_id INT, @periodo CHAR(7))
RETURNS TABLE
AS
RETURN
WITH periodo AS (
    SELECT CONVERT(DATE, @periodo + ''-01'', 23) AS inicio,
           EOMONTH(CONVERT(DATE, @periodo + ''-01'', 23)) AS fin
),
contrato AS (
    SELECT c.tarifa_id,
           CASE WHEN c.fecha_alta > p.inicio THEN c.fecha_alta ELSE p.inicio END AS desde,
           CASE WHEN c.fecha_baja < p.fin THEN c.fecha_baja ELSE p.fin END AS hasta,
           DATEDIFF(DAY, p.inicio, p.fin) + 1 AS dias_mes
    FROM dbo.contratos c
    CROSS JOIN periodo p
    WHERE c.id = @contrato_id
),
tramos AS (
    SELECT tp.precio_kwh, tp.termino_fijo_mes, c.dias_mes,
           DATEDIFF(DAY, c.desde, c.hasta) + 1 AS dias_contrato,
           DATEDIFF(DAY,
                    CASE WHEN tp.vigente_desde > c.desde THEN tp.vigente_desde ELSE c.desde END,
                    CASE WHEN tp.vigente_hasta < c.hasta THEN tp.vigente_hasta ELSE c.hasta END) + 1 AS dias
    FROM contrato c
    JOIN dbo.tarifas_precios tp
      ON tp.tarifa_id = c.tarifa_id
     AND tp.vigente_desde <= c.hasta
     AND (tp.vigente_hasta IS NULL OR tp.vigente_hasta >= c.desde)
),
consumo AS (
    SELECT ISNULL(SUM(l.consumo_kwh), 0) AS kwh
    FROM dbo.lecturas l
    CROSS JOIN periodo p
    WHERE l.contrato_id = @contrato_id
      AND l.fecha_hasta BETWEEN p.inicio AND p.fin
)
SELECT CAST(MAX(k.kwh) AS DECIMAL(12,3)) AS consumo_kwh,
       CAST(SUM(t.precio_kwh * t.dias) / SUM(t.dias) AS DECIMAL(10,6)) AS precio_kwh,
       CAST(ROUND(MAX(k.kwh) * SUM(t.precio_kwh * t.dias) / MAX(t.dias_contrato), 2) AS DECIMAL(12,2)) AS importe_energia,
       CAST(ROUND(SUM(t.termino_fijo_mes * t.dias) / MAX(t.dias_mes), 2) AS DECIMAL(10,2)) AS importe_fijo,
       SUM(t.dias) AS dias_con_precio,
       MAX(t.dias_contrato) AS dias_contrato
FROM tramos t
CROSS JOIN consumo k;
');

SET XACT_ABORT ON;

DECLARE @periodo CHAR(7) = '2026-09';
DECLARE @inicio DATE = CONVERT(DATE, @periodo + '-01', 23);
DECLARE @correccion_id INT;

IF OBJECT_ID('dbo.incidencia_2026_09_correccion') IS NOT NULL
BEGIN
    PRINT 'La corrección ya estaba aplicada. No se hace nada.';
    RETURN;
END

BEGIN TRY
    BEGIN TRANSACTION;

    PRINT '1. Copia de seguridad de las facturas de septiembre y de las lecturas repetidas';
    SELECT * INTO dbo.incidencia_2026_09_facturas_antes
    FROM dbo.facturas
    WHERE periodo = @periodo;

    SELECT d.* INTO dbo.incidencia_2026_09_lecturas_borradas
    FROM dbo.lecturas d
    WHERE EXISTS (SELECT 1 FROM dbo.lecturas o
                  WHERE o.contrato_id = d.contrato_id
                    AND o.fecha_desde = d.fecha_desde
                    AND o.fecha_hasta = d.fecha_hasta
                    AND o.lectura_contador = d.lectura_contador
                    AND o.consumo_kwh = d.consumo_kwh
                    AND o.tipo = d.tipo
                    AND o.id < d.id);

    PRINT '2. Borrado de las lecturas cargadas dos veces';
    DELETE FROM dbo.lecturas
    WHERE id IN (SELECT id FROM dbo.incidencia_2026_09_lecturas_borradas);

    PRINT '3. Cierre de la facturación que se quedó a medias';
    UPDATE dbo.log_procesos
    SET estado = 'ERROR',
        fin = (SELECT MAX(fecha_creacion) FROM dbo.facturas WHERE ejecucion_id = log_procesos.id),
        filas_procesadas = (SELECT COUNT(*) FROM dbo.facturas WHERE ejecucion_id = log_procesos.id),
        mensaje = N'Cortada por timeout (Jenkins #48). Cerrada al corregir la incidencia.'
    WHERE proceso = 'FACTURACION_MENSUAL' AND parametros = @periodo AND estado = 'EN_CURSO';

    INSERT INTO dbo.log_procesos (proceso, parametros, inicio, estado, usuario, mensaje)
    VALUES ('CORRECCION_FACTURAS', @periodo, SYSDATETIME(), 'EN_CURSO', SUSER_SNAME(),
            N'Incidencia de septiembre: facturas duplicadas, lecturas repetidas y precio sin prorratear');
    SET @correccion_id = SCOPE_IDENTITY();

    PRINT '4. Cálculo del importe correcto de cada contrato';
    SELECT f.contrato_id, calc.*,
           CAST(calc.importe_energia + calc.importe_fijo AS DECIMAL(12,2)) AS total
    INTO #correcto
    FROM (SELECT DISTINCT contrato_id FROM dbo.facturas WHERE periodo = @periodo AND estado <> 'anulada') f
    CROSS APPLY dbo.fn_calcular_factura(f.contrato_id, @periodo) calc;

    IF EXISTS (SELECT 1 FROM #correcto WHERE dias_con_precio IS NULL OR dias_con_precio <> dias_contrato)
        THROW 50101, 'Hay contratos sin precio para todos los días del mes. Revisar tarifas_precios.', 1;

    -- Facturas de los contratos afectados. Si el importe es correcto se conserva una (la pagada, o la más antigua).
    SELECT f.id, f.numero, f.contrato_id, f.estado, f.total,
           c.total AS total_correcto,
           MIN(CASE WHEN f.total = c.total THEN 1 ELSE 0 END) OVER (PARTITION BY f.contrato_id) AS importe_ok,
           COUNT(*) OVER (PARTITION BY f.contrato_id) AS facturas,
           ROW_NUMBER() OVER (PARTITION BY f.contrato_id
                              ORDER BY CASE WHEN f.estado = 'pagada' THEN 0 ELSE 1 END, f.id) AS orden
    INTO #facturas
    FROM dbo.facturas f
    JOIN #correcto c ON c.contrato_id = f.contrato_id
    WHERE f.periodo = @periodo AND f.estado <> 'anulada';

    DELETE FROM #facturas WHERE facturas = 1 AND importe_ok = 1;

    SELECT contrato_id,
           CONCAT('F', LEFT(@periodo, 4), '-', FORMAT(NEXT VALUE FOR dbo.seq_factura OVER (ORDER BY contrato_id), '000000')) AS numero
    INTO #nuevas
    FROM #facturas
    WHERE importe_ok = 0 AND orden = 1;

    PRINT '5. Registro de lo que hay que hacer con cada cliente';
    SELECT g.contrato_id, ct.cliente_id, ct.forma_pago,
           CONCAT_WS(' + ',
               CASE WHEN g.facturas > 1 THEN 'Factura duplicada' END,
               CASE WHEN g.importe_ok = 0 THEN 'Importe incorrecto' END) AS causa,
           g.anuladas AS facturas_anuladas,
           COALESCE(n.numero, g.conservada) AS factura_valida,
           g.pagado AS importe_pagado,
           c.total AS importe_correcto,
           CAST(g.pagado - c.total AS DECIMAL(12,2)) AS saldo,
           CASE WHEN g.pagado = 0 THEN N'Nada que devolver: el cliente pagará la factura válida'
                WHEN g.pagado > c.total THEN CONCAT(N'Devolver ', g.pagado - c.total, N' €')
                WHEN g.pagado < c.total THEN CONCAT(N'Cobrar ', c.total - g.pagado, N' € pendientes')
                ELSE N'Nada: ya pagó el importe correcto'
           END AS accion
    INTO dbo.incidencia_2026_09_correccion
    FROM (
        SELECT contrato_id,
               MAX(facturas) AS facturas,
               MIN(importe_ok) AS importe_ok,
               SUM(CASE WHEN estado = 'pagada' THEN total ELSE 0 END) AS pagado,
               MAX(CASE WHEN importe_ok = 1 AND orden = 1 THEN numero END) AS conservada,
               STRING_AGG(CASE WHEN NOT (importe_ok = 1 AND orden = 1) THEN numero END, ', ') AS anuladas
        FROM #facturas
        GROUP BY contrato_id
    ) g
    JOIN #correcto c ON c.contrato_id = g.contrato_id
    JOIN dbo.contratos ct ON ct.id = g.contrato_id
    LEFT JOIN #nuevas n ON n.contrato_id = g.contrato_id;

    PRINT '6. Anulación de las facturas que sobran o tienen mal el importe';
    UPDATE dbo.facturas
    SET estado = 'anulada'
    WHERE id IN (SELECT id FROM #facturas WHERE NOT (importe_ok = 1 AND orden = 1));

    PRINT '7. Facturas nuevas, en borrador, con el importe correcto';
    INSERT INTO dbo.facturas (numero, contrato_id, periodo, fecha_desde, fecha_hasta, consumo_kwh,
                              precio_kwh, importe_energia, importe_fijo, total, estado,
                              ejecucion_id, fecha_creacion)
    SELECT n.numero, n.contrato_id, @periodo, @inicio, EOMONTH(@inicio),
           c.consumo_kwh, c.precio_kwh, c.importe_energia, c.importe_fijo, c.total,
           'borrador', @correccion_id, SYSDATETIME()
    FROM #nuevas n
    JOIN #correcto c ON c.contrato_id = n.contrato_id;

    PRINT '8. Comprobaciones finales';
    IF EXISTS (SELECT 1 FROM dbo.facturas
               WHERE periodo = @periodo AND estado <> 'anulada'
               GROUP BY contrato_id HAVING COUNT(*) <> 1)
        THROW 50102, 'Algún contrato no tiene exactamente una factura válida.', 1;

    IF EXISTS (SELECT 1 FROM dbo.facturas f
               JOIN #correcto c ON c.contrato_id = f.contrato_id
               WHERE f.periodo = @periodo AND f.estado <> 'anulada' AND f.total <> c.total)
        THROW 50103, 'Alguna factura válida no tiene el importe correcto.', 1;

    IF EXISTS (SELECT 1 FROM dbo.lecturas d JOIN dbo.lecturas o
                 ON o.contrato_id = d.contrato_id AND o.fecha_desde = d.fecha_desde
                AND o.fecha_hasta = d.fecha_hasta AND o.id < d.id)
        THROW 50104, 'Siguen existiendo lecturas repetidas.', 1;

    UPDATE dbo.log_procesos
    SET fin = SYSDATETIME(), estado = 'OK',
        filas_procesadas = (SELECT COUNT(*) FROM dbo.incidencia_2026_09_correccion)
    WHERE id = @correccion_id;

    COMMIT TRANSACTION;
    PRINT 'Corrección aplicada.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    PRINT CONCAT(N'Corrección NO aplicada, no se ha cambiado nada: ', ERROR_MESSAGE());
    THROW;
END CATCH;

PRINT 'Resumen por causa';
SELECT causa, COUNT(*) AS contratos,
       SUM(CASE WHEN saldo > 0 THEN saldo ELSE 0 END) AS a_devolver,
       SUM(CASE WHEN saldo < 0 AND importe_pagado > 0 THEN -saldo ELSE 0 END) AS a_cobrar
FROM dbo.incidencia_2026_09_correccion
GROUP BY causa;

PRINT 'Detalle por cliente';
SELECT * FROM dbo.incidencia_2026_09_correccion ORDER BY contrato_id;
