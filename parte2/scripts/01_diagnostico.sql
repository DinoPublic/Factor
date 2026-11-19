/*
    01_diagnostico.sql — Incidencia en la facturación de septiembre de 2026

    Solo lectura, para diagnosticar la causa de la incidencia y su alcance.

    Ejecución:
    docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' -C -I -f 65001 -d facturacion -W -s "|" < scripts/01_diagnostico.sql
*/
SET NOCOUNT ON;

DECLARE @periodo CHAR(7) = '2026-09';
DECLARE @inicio DATE = CONVERT(DATE, @periodo + '-01', 23);
DECLARE @fin DATE = EOMONTH(@inicio);
DECLARE @ultima_facturacion INT = (SELECT MAX(id) FROM dbo.log_procesos
                                   WHERE proceso = 'FACTURACION_MENSUAL' AND parametros = @periodo);

PRINT '1. La facturación de septiembre se lanzó dos veces: la primera se cortó a medias y la segunda la repitió entera';
SELECT lp.id AS ejecucion, lp.proceso, lp.usuario, lp.inicio, lp.fin, lp.estado,
       lp.filas_procesadas AS facturas_procesadas,
       COUNT(f.id) AS facturas_creadas,
       DATEDIFF(SECOND, MIN(f.fecha_creacion), MAX(f.fecha_creacion)) / NULLIF(COUNT(f.id) - 1, 0) AS segundos_por_factura
FROM dbo.log_procesos lp
LEFT JOIN dbo.facturas f ON f.ejecucion_id = lp.id
WHERE lp.parametros = @periodo
GROUP BY lp.id, lp.proceso, lp.usuario, lp.inicio, lp.fin, lp.estado, lp.filas_procesadas
ORDER BY lp.id;

PRINT '2. Clientes con dos facturas de septiembre';
WITH duplicadas AS (
    SELECT contrato_id
    FROM dbo.facturas
    WHERE periodo = @periodo AND estado <> 'anulada'
    GROUP BY contrato_id
    HAVING COUNT(*) > 1
)
SELECT c.forma_pago,
       COUNT(DISTINCT f.contrato_id) AS contratos,
       SUM(CASE WHEN f.estado = 'pagada' THEN 1 ELSE 0 END) AS facturas_ya_pagadas,
       CAST(SUM(f.total) / 2 AS DECIMAL(12,2)) AS importe_de_mas
FROM dbo.facturas f
JOIN duplicadas d ON d.contrato_id = f.contrato_id
JOIN dbo.contratos c ON c.id = f.contrato_id
WHERE f.periodo = @periodo AND f.estado <> 'anulada'
GROUP BY c.forma_pago;

PRINT '3. Lecturas cargadas dos veces: el mismo fichero de SUR se cargó dos días seguidos y se cobró el doble de consumo';
WITH repetidas AS (
    SELECT d.contrato_id, o.fichero_origen AS fichero_original, d.fichero_origen AS fichero_repetido, d.consumo_kwh
    FROM dbo.lecturas d
    JOIN dbo.lecturas o
      ON o.contrato_id = d.contrato_id
     AND o.fecha_desde = d.fecha_desde
     AND o.fecha_hasta = d.fecha_hasta
     AND o.lectura_contador = d.lectura_contador
     AND o.consumo_kwh = d.consumo_kwh
     AND o.tipo = d.tipo
     AND o.id < d.id
)
SELECT r.fichero_original, r.fichero_repetido,
       COUNT(*) AS contratos,
       SUM(r.consumo_kwh) AS kwh_de_mas,
       CAST(SUM(r.consumo_kwh * f.precio_kwh) AS DECIMAL(12,2)) AS importe_de_mas
FROM repetidas r
JOIN dbo.facturas f ON f.contrato_id = r.contrato_id AND f.ejecucion_id = @ultima_facturacion
GROUP BY r.fichero_original, r.fichero_repetido;

PRINT '4. Precio de TEMPO_FIJA: subió el 15 de septiembre, pero se cobró el precio nuevo todo el mes';
WITH tramos AS (
    SELECT tarifa_id, precio_kwh,
           DATEDIFF(DAY,
                    CASE WHEN vigente_desde > @inicio THEN vigente_desde ELSE @inicio END,
                    CASE WHEN vigente_hasta < @fin THEN vigente_hasta ELSE @fin END) + 1 AS dias
    FROM dbo.tarifas_precios
    WHERE vigente_desde <= @fin AND (vigente_hasta IS NULL OR vigente_hasta >= @inicio)
),
precio_correcto AS (
    SELECT tarifa_id, CAST(SUM(precio_kwh * dias) / SUM(dias) AS DECIMAL(10,6)) AS precio
    FROM tramos
    GROUP BY tarifa_id
    HAVING COUNT(*) > 1
),
consumo_real AS (
    SELECT l.contrato_id, SUM(l.consumo_kwh) AS kwh
    FROM dbo.lecturas l
    WHERE l.fecha_hasta BETWEEN @inicio AND @fin
      AND NOT EXISTS (SELECT 1 FROM dbo.lecturas o
                      WHERE o.contrato_id = l.contrato_id AND o.fecha_desde = l.fecha_desde
                        AND o.fecha_hasta = l.fecha_hasta AND o.consumo_kwh = l.consumo_kwh AND o.id < l.id)
    GROUP BY l.contrato_id
)
SELECT t.codigo AS tarifa, f.precio_kwh AS precio_aplicado, p.precio AS precio_correcto,
       COUNT(*) AS contratos,
       CAST(SUM(r.kwh * (f.precio_kwh - p.precio)) AS DECIMAL(12,2)) AS importe_de_mas
FROM dbo.facturas f
JOIN dbo.contratos c ON c.id = f.contrato_id
JOIN dbo.tarifas t ON t.id = c.tarifa_id
JOIN precio_correcto p ON p.tarifa_id = c.tarifa_id
JOIN consumo_real r ON r.contrato_id = f.contrato_id
WHERE f.ejecucion_id = @ultima_facturacion
GROUP BY t.codigo, f.precio_kwh, p.precio;

PRINT '5. Reclamaciones recibidas y su causa';
WITH facturas_cliente AS (
    SELECT contrato_id, COUNT(*) AS facturas, MAX(total) AS total
    FROM dbo.facturas
    WHERE periodo = @periodo AND estado <> 'anulada'
    GROUP BY contrato_id
)
SELECT r.id AS reclamacion, r.cliente_id AS cliente, t.codigo AS tarifa, c.distribuidora, f.facturas, f.total,
       CONCAT_WS(' + ',
           CASE WHEN f.facturas > 1 THEN 'Factura duplicada' END,
           CASE WHEN EXISTS (SELECT 1 FROM dbo.lecturas d JOIN dbo.lecturas o
                               ON o.contrato_id = d.contrato_id AND o.fecha_desde = d.fecha_desde
                              AND o.fecha_hasta = d.fecha_hasta AND o.consumo_kwh = d.consumo_kwh AND o.id < d.id
                             WHERE d.contrato_id = c.id AND d.fecha_hasta BETWEEN @inicio AND @fin)
                THEN 'Lectura cargada dos veces' END,
           CASE WHEN t.codigo = 'TEMPO_FIJA' THEN 'Precio sin prorratear' END,
           CASE WHEN (SELECT TOP 1 p.tipo FROM dbo.lecturas p
                      WHERE p.contrato_id = c.id AND p.fecha_hasta < @inicio
                      ORDER BY p.fecha_hasta DESC) = 'estimada'
                THEN 'Sin error: lectura real tras meses estimados' END
       ) AS causa,
       r.texto
FROM dbo.reclamaciones r
JOIN dbo.contratos c ON c.cliente_id = r.cliente_id
JOIN dbo.tarifas t ON t.id = c.tarifa_id
JOIN facturas_cliente f ON f.contrato_id = c.id
ORDER BY r.id;

PRINT '6. Resumen: a cuántos clientes afecta';
WITH duplicadas AS (
    SELECT contrato_id FROM dbo.facturas
    WHERE periodo = @periodo AND estado <> 'anulada'
    GROUP BY contrato_id HAVING COUNT(*) > 1
),
lectura_repetida AS (
    SELECT DISTINCT d.contrato_id
    FROM dbo.lecturas d JOIN dbo.lecturas o
      ON o.contrato_id = d.contrato_id AND o.fecha_desde = d.fecha_desde AND o.fecha_hasta = d.fecha_hasta
     AND o.lectura_contador = d.lectura_contador AND o.consumo_kwh = d.consumo_kwh AND o.tipo = d.tipo AND o.id < d.id
    WHERE d.fecha_hasta BETWEEN @inicio AND @fin
),
precio AS (
    SELECT DISTINCT f.contrato_id
    FROM dbo.facturas f JOIN dbo.contratos c ON c.id = f.contrato_id
    WHERE f.periodo = @periodo AND f.estado <> 'anulada'
      AND c.tarifa_id IN (SELECT tarifa_id FROM dbo.tarifas_precios
                          WHERE vigente_desde <= @fin AND (vigente_hasta IS NULL OR vigente_hasta >= @inicio)
                          GROUP BY tarifa_id HAVING COUNT(*) > 1)
),
afectados AS (
    SELECT contrato_id FROM duplicadas
    UNION SELECT contrato_id FROM lectura_repetida
    UNION SELECT contrato_id FROM precio
)
SELECT (SELECT COUNT(DISTINCT contrato_id) FROM dbo.facturas WHERE periodo = @periodo) AS contratos_facturados,
       (SELECT COUNT(*) FROM duplicadas) AS con_factura_duplicada,
       (SELECT COUNT(*) FROM lectura_repetida) AS con_lectura_repetida,
       (SELECT COUNT(*) FROM precio) AS con_precio_sin_prorratear,
       (SELECT COUNT(*) FROM afectados) AS contratos_afectados,
       (SELECT COUNT(DISTINCT c.cliente_id) FROM dbo.contratos c JOIN afectados a ON a.contrato_id = c.id) AS clientes_afectados;
