# Incidencia en la facturación de septiembre de 2026

**Para:** Responsable de Facturación

## En resumen

En la facturación de septiembre coincidieron **tres errores**:

1. **Facturas duplicadas.** El proceso se cortó a medias y, al relanzarlo a mano, volvió a facturar a quien ya tenía factura.
2. **Consumo doble en SUR.** El fichero de lecturas de la distribuidora SUR se cargó dos veces.
3. **Precio nuevo aplicado a todo el mes.** La subida de *Tempo Fija* del 15 de septiembre se cobró también los días anteriores.

Afecta a **94 clientes (97 de los 142 contratos)** y se facturaron **5.884 € de más**. Lo más urgente: **46 clientes domiciliados a los que el banco ya ha cobrado dos veces**.

La corrección y los cambios para que no se repita están preparados. Antes de aplicarlos necesitamos que decidáis los puntos del final.

## Qué pasó

- **3 de octubre.** SUR reenvió su fichero de lecturas de septiembre, idéntico al del día 2, y se cargó otra vez. Como el proceso suma todas las lecturas del mes, sus 44 contratos se facturaron con el doble de consumo.
- **5 de octubre, 02:00.** La facturación automática fue muy lenta: creó 61 facturas entre las 02:00 y las 02:44, unos 44 segundos por factura cuando lo normal es menos de 1. A los 45 minutos se cortó y no deshizo lo que ya había creado.
- **5 de octubre, 08:15.** Un usuario (jlopez) lo relanzó a mano por la mañana. Como el proceso no comprueba si el mes ya está facturado, volvió a facturar a todos y esos 61 contratos quedaron con dos facturas.
- **6 de octubre.** Se enviaron las 203 facturas y a los domiciliados se les pasaron los dos recibos.
- **Además**, el proceso usa siempre el precio de hoy. Los 44 contratos de *Tempo Fija* pagaron todo septiembre al precio nuevo.

**Lo que no es un error:** a algunos clientes se les facturaron meses anteriores con una lectura estimada, más baja de lo que gastaron. En septiembre llegó la lectura real y la factura recoge ese consumo pendiente. Sale alta, pero es correcta (cliente 97).

## A quién afecta

| Problema | Contratos | Facturado de más |
|---|---|---|
| Factura duplicada | 61 | 3.913,10 € |
| Consumo doble (SUR) | 44 | 1.884,51 € |
| Precio sin repartir por días (*Tempo Fija*) | 44 | 86,62 € |
| **Total** | **97** (algunos tienen varios problemas) | **5.884,23 €** |

Una vez corregido:

- **80 contratos** pagaron de más: hay que **devolverles 4.729,52 €**.
- **15 contratos** no habían pagado todavía: pagarán la factura correcta (753,68 € en total).
- **2 contratos** quedan sin movimiento de dinero.

## Qué proponemos

**Corregir septiembre** (`02_correccion.sql`):

- No se borra ninguna factura: las que sobran o están mal se **anulan** y quedan para auditoría.
- Si solo sobraba una copia (23 contratos), se anula la copia y se conserva la pagada.
- Si el importe estaba mal (74 contratos), se anula y se crea una factura nueva, en borrador, con el importe correcto.
- Se borran las 44 lecturas repetidas de SUR, guardando antes una copia.
- Queda una **lista con cada cliente afectado** y qué hacer con él (devolver X €, nada, etc.). Es la lista de trabajo para Facturación y Atención al Cliente.

**Respuesta a las reclamaciones:**

| Cliente | Qué le pasó | Qué hacer |
|---|---|---|
| 7 | Dos recibos cobrados | Devolver 35,88 € |
| 23 | Dos facturas iguales, sin pagar | Vale la F2026-000725 (52,49 €); se anula la otra |
| 41 | Dos recibos y consumo de SUR doble | Devolver 130,59 € |
| 88 | Consumo de SUR doble | Devolver 28,82 € |
| 104 | Precio nuevo todo el mes | Devolver 1,78 € |
| 119 | Consumo de SUR doble, sin pagar | Nueva factura de 43,80 € |
| 97 | Factura alta tras meses estimados | **Es correcta.** Explicárselo y ofrecer fraccionar el pago |

**Prevención para que no vuelva a pasar** (`03_prevencion.sql`):

- Si la facturación falla, **no deja nada a medias**.
- **No se puede facturar dos veces el mismo mes**, aunque alguien lo relance a mano.
- **El precio se reparte por días** cuando cambia a mitad de mes.
- **La base de datos rechaza** una segunda factura del mismo contrato y mes, y una lectura repetida.
- **El proceso es mucho más rápido**: tarda segundos en lugar de minutos.
- **Lista de control antes de enviar**: señala facturas con consumo de más del doble de lo habitual o tras meses estimados.

Y en la forma de trabajar se debería controlar:
- No relanzar la facturación sin revisar antes qué dejó hecha.
- No enviar facturas con avisos sin revisar.
- Confirmar con la distribuidora cuando reenvía un fichero.

## Lo que tenéis que decidir

1. **Devolver o compensar** los 4.729,52 €. Lo cobrado por duplicado mejor devolverlo ya.
2. **Anular o rectificar.** El sistema no tiene facturas rectificativas. Hay que confirmar con Administración si las facturas ya enviadas necesitan una.
3. **Reparto del precio por días** (14 días al precio antiguo y 16 al nuevo), porque no tenemos el consumo diario. ¿Os parece bien este criterio?
4. **Cuándo enviar las 74 facturas nuevas** y qué comunicación mandar a los 94 clientes.
5. **Por qué fue tan lento el proceso esa noche.** Los datos no lo explican; hay que preguntar a Sistemas si coincidieron copias de seguridad, bloqueos u otros procesos a esa hora.
6. **Por qué SUR reenvió el fichero**: confirmarlo con la distribuidora.

---

## Anexo técnico

**Cómo ejecutarlo.** Desde `parte2/`, con la base de datos recién levantada (`docker compose up -d`), en este orden:

```bash
SQLCMD="docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P Prueba.Tecnica.2026 -C -I -f 65001 -d facturacion -b"

$SQLCMD -W -s "|" < scripts/01_diagnostico.sql   # solo lectura
$SQLCMD < scripts/02_correccion.sql              # corrige septiembre
$SQLCMD < scripts/03_prevencion.sql              # índices, procedimiento nuevo y lista de control
```

Cada script es un único bloque, así que también se puede ejecutar entero desde DBeaver o SSMS.

**Decisiones:**

- **Corrección segura:** todo en una transacción, con copia previa (`incidencia_2026_09_facturas_antes` e `incidencia_2026_09_lecturas_borradas`) y comprobación final. Si algo no cuadra, se deshace todo. Si se ejecuta dos veces, la segunda no hace nada.
- **Criterios genéricos, no listas de ids:** las lecturas repetidas se detectan por valores idénticos y los importes erróneos comparando con el recálculo.
- **Un único cálculo de factura** (`fn_calcular_factura`), compartido por la corrección y el procedimiento nuevo.
- **El procedimiento mantiene su nombre y parámetros:** Jenkins y la aplicación no cambian.
- **Ante datos dudosos, el proceso se para** en lugar de facturar mal.

**Limitaciones:**

- El reparto por días supone que el consumo es igual todos los días del mes.
- Las facturas anuladas dejan huecos en la numeración. Si tiene que ser correlativa, hay que cambiar el mecanismo.
- El índice único de facturas excluye las anuladas, y eso exige que las conexiones usen las opciones ANSI por defecto. El driver de la aplicación ya las usa, pero conviene comprobarlo en producción.
