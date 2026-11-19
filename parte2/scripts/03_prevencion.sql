/*
    03_prevencion.sql — Que no vuelva a pasar

    Idempotente.

    Ejecución:
    docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Prueba.Tecnica.2026' -C -I -f 65001 -d facturacion -b < scripts/03_prevencion.sql
*/
SET NOCOUNT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;

IF OBJECT_ID('dbo.incidencia_2026_09_correccion') IS NULL
    THROW 50201, 'Antes hay que ejecutar 02_correccion.sql.', 1;

PRINT '1. Índices únicos: una factura válida por contrato y mes, y una lectura por contrato y periodo';
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ux_facturas_contrato_periodo')
    CREATE UNIQUE INDEX ux_facturas_contrato_periodo
        ON dbo.facturas (contrato_id, periodo)
        WHERE estado <> 'anulada';

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ux_lecturas_contrato_periodo')
    CREATE UNIQUE INDEX ux_lecturas_contrato_periodo
        ON dbo.lecturas (contrato_id, fecha_desde, fecha_hasta);

PRINT '2. Procedimiento de facturación: todo o nada, sin ejecuciones dobles y con el precio repartido por días';
-- Se crea con EXEC para que todo el script sea un único bloque (sin GO).
EXEC (N'
CREATE OR ALTER PROCEDURE dbo.sp_facturar_periodo
    @periodo CHAR(7)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @inicio DATE = TRY_CONVERT(DATE, @periodo + ''-01'', 23);
    DECLARE @fin DATE = EOMONTH(@inicio);
    DECLARE @ejecucion_id INT;
    DECLARE @lock INT;
    DECLARE @facturas INT;

    IF @inicio IS NULL
        THROW 50001, ''Periodo no válido: se espera YYYY-MM.'', 1;

    INSERT INTO dbo.log_procesos (proceso, parametros, inicio, estado, usuario)
    VALUES (''FACTURACION_MENSUAL'', @periodo, SYSDATETIME(), ''EN_CURSO'', SUSER_SNAME());
    SET @ejecucion_id = SCOPE_IDENTITY();

    BEGIN TRY
        BEGIN TRANSACTION;

        EXEC @lock = sp_getapplock @Resource = ''sp_facturar_periodo'', @LockMode = ''Exclusive'',
                                   @LockOwner = ''Transaction'', @LockTimeout = 0;
        IF @lock < 0
            THROW 50002, ''Ya hay una facturación en curso.'', 1;

        UPDATE dbo.log_procesos
        SET estado = ''ERROR'', fin = SYSDATETIME(), mensaje = N''Interrumpida; sus cambios se deshicieron.''
        WHERE proceso = ''FACTURACION_MENSUAL'' AND estado = ''EN_CURSO'' AND id <> @ejecucion_id;

        IF EXISTS (SELECT 1 FROM dbo.facturas WHERE periodo = @periodo AND estado <> ''anulada'')
            THROW 50003, ''Este mes ya está facturado. Para refacturar hay que anular antes sus facturas.'', 1;

        SELECT c.id AS contrato_id, calc.*
        INTO #calculo
        FROM dbo.contratos c
        CROSS APPLY dbo.fn_calcular_factura(c.id, @periodo) calc
        WHERE c.fecha_alta <= @fin AND (c.fecha_baja IS NULL OR c.fecha_baja >= @inicio);

        IF EXISTS (SELECT 1 FROM #calculo WHERE dias_con_precio IS NULL OR dias_con_precio <> dias_contrato)
            THROW 50004, ''Hay contratos sin precio para todos los días del mes. Revisar tarifas_precios.'', 1;

        IF EXISTS (SELECT 1 FROM dbo.lecturas a
                   JOIN dbo.lecturas b
                     ON b.contrato_id = a.contrato_id AND b.id > a.id
                    AND b.fecha_desde <= a.fecha_hasta AND a.fecha_desde <= b.fecha_hasta
                   WHERE a.fecha_hasta BETWEEN @inicio AND @fin)
            THROW 50005, ''Hay lecturas que se solapan en el mes. Revisar las cargas antes de facturar.'', 1;

        INSERT INTO dbo.facturas (numero, contrato_id, periodo, fecha_desde, fecha_hasta, consumo_kwh,
                                  precio_kwh, importe_energia, importe_fijo, total, estado,
                                  ejecucion_id, fecha_creacion)
        SELECT CONCAT(''F'', LEFT(@periodo, 4), ''-'', FORMAT(NEXT VALUE FOR dbo.seq_factura OVER (ORDER BY contrato_id), ''000000'')),
               contrato_id, @periodo, @inicio, @fin, consumo_kwh,
               precio_kwh, importe_energia, importe_fijo, importe_energia + importe_fijo, ''borrador'',
               @ejecucion_id, SYSDATETIME()
        FROM #calculo;
        SET @facturas = @@ROWCOUNT;

        UPDATE dbo.log_procesos
        SET fin = SYSDATETIME(), estado = ''OK'', filas_procesadas = @facturas
        WHERE id = @ejecucion_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        UPDATE dbo.log_procesos
        SET fin = SYSDATETIME(), estado = ''ERROR'', filas_procesadas = 0, mensaje = LEFT(ERROR_MESSAGE(), 400)
        WHERE id = @ejecucion_id;

        THROW;
    END CATCH
END
');

PRINT '3. Vista con las facturas a revisar antes de enviarlas';
EXEC (N'
CREATE OR ALTER VIEW dbo.vw_control_emision
AS
SELECT f.id AS factura_id, f.numero, f.contrato_id, f.periodo, f.consumo_kwh, f.total, m.motivo
FROM dbo.facturas f
CROSS APPLY (
    SELECT ''Consumo de más del doble de lo habitual'' AS motivo
    WHERE f.consumo_kwh > 2 * (SELECT AVG(h.consumo_kwh)
                               FROM (SELECT TOP 3 x.consumo_kwh FROM dbo.facturas x
                                     WHERE x.contrato_id = f.contrato_id AND x.periodo < f.periodo
                                       AND x.estado <> ''anulada''
                                     ORDER BY x.periodo DESC) h)
    UNION ALL
    SELECT ''Lectura real después de meses estimados''
    WHERE EXISTS (SELECT 1 FROM dbo.lecturas l
                  WHERE l.contrato_id = f.contrato_id
                    AND l.fecha_hasta BETWEEN f.fecha_desde AND f.fecha_hasta
                    AND l.tipo = ''real''
                    AND (SELECT TOP 1 p.tipo FROM dbo.lecturas p
                         WHERE p.contrato_id = l.contrato_id AND p.fecha_hasta < l.fecha_desde
                         ORDER BY p.fecha_hasta DESC) = ''estimada'')
) m
WHERE f.estado = ''borrador'';
');

PRINT 'Prevención aplicada. Facturas de septiembre a revisar antes de enviarlas:';
SELECT motivo, COUNT(*) AS facturas
FROM dbo.vw_control_emision
GROUP BY motivo;
