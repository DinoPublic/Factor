# Parte 1: Solución

API REST en **Laravel 13** (PHP 8.3) para recibir lecturas de contador de la distribuidora.

## Cómo ejecutarlo

Desde la carpeta `parte1/`:

```bash
docker compose up -d --build
```

- La API queda en `http://localhost:8000/api/v1/lecturas`.
- Al arrancar, el contenedor `app` instala dependencias, crea el `.env` desde `.env.example`, ejecuta las migraciones y levanta el servidor.
- El README principal nos pide activar la opción Rosetta (Mac con chip Apple). Esto se hace desde el Docker Desktop.

Tests (SQLite en memoria, no tocan la base de datos de SQL Server):

```bash
docker compose exec app php artisan test
```

## Ejemplo de petición

```bash
curl -X POST http://localhost:8000/api/v1/lecturas \
  -H "Authorization: Bearer token-distribuidora-pruebas" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"cups": "ES0904998698797309NB", "fecha_lectura": "2026-11-01", "lectura_kwh": 11210, "tipo": "real"}'
```

Respuesta `201 Created`:

```json
{
  "guardada": true,
  "duplicada": false,
  "lectura": {
    "id": 231,
    "cups": "ES0904998698797309NB",
    "fecha_lectura": "2026-11-01",
    "lectura_kwh": 11210,
    "tipo": "real",
    "fecha_recepcion": "2026-11-19T16:45:00+01:00"
  }
}
```

Si se repite la misma petición, la respuesta es `200 OK` con `"duplicada": true` y la lectura original.

Ejemplo de rechazo (contrato de baja, CUPS `ES0904229486628360ZC`), `422`:

```json
{
  "guardada": false,
  "error": {
    "codigo": "CONTRATO_DE_BAJA",
    "mensaje": "El contrato del CUPS ES0904229486628360ZC está de baja desde 2026-08-31 y no admite lecturas.",
    "vigencias": [{ "fecha_alta": "2021-02-01", "fecha_baja": "2026-08-31", "estado": "baja" }]
  }
}
```

## Contrato de la API

`POST /api/v1/lecturas` con cabecera `Authorization: Bearer <token>`.

| Campo | Tipo | Reglas |
|-------|------|--------|
| `cups` | string | Obligatorio. `ES` + 16 dígitos + 2 letras de control válidas (+ 2 caracteres opcionales de punto frontera). Se normaliza a mayúsculas. |
| `fecha_lectura` | string | Obligatorio. `AAAA-MM-DD`, no futura. |
| `lectura_kwh` | int | Obligatorio. Entero ≥ 0. |
| `tipo` | string | Obligatorio. `real` o `estimada`. |

Todas las respuestas llevan `guardada: true/false`. Cuando es `false`, `error.codigo` indica el motivo para que la distribuidora pueda tratarlo de forma automática:

| Código | HTTP | Cuándo |
|--------|------|--------|
| `NO_AUTORIZADO` | 401 | Falta el token o no es válido. |
| `DATOS_NO_VALIDOS` | 422 | Algún campo no cumple las reglas (`error.campos` detalla cada uno). |
| `CUPS_DESCONOCIDO` | 422 | No hay ningún contrato con ese CUPS. |
| `CONTRATO_DE_BAJA` | 422 | El contrato de esa fecha está de baja. |
| `FUERA_DE_VIGENCIA` | 422 | La fecha no cae dentro de ningún contrato del CUPS. |
| `LECTURA_SUPERA_CONTADOR` | 422 | El valor no cabe en los dígitos del contador. |
| `LECTURA_INCOHERENTE` | 422 | Menor que la lectura real anterior o mayor que la real posterior. |
| `LECTURA_YA_EXISTE` | 409 | Ya hay una lectura con otros datos en esa fecha. No se sobrescribe. |

## Decisiones principales

**Framework.** He usado Laravel porque es el framework con el que tengo más experiencia y sé organizar mejor los ficheros. Usa algunas cosas propias como Eloquent, Middleware para el token y los Request pero el resto del código es fácil de llevar a otro framework.

**Estructura.** El controlador solo invoca al caso de uso y responde. Toda la lógica está en `RegistrarLecturaUseCase`, que recibe un DTO (`LecturaEntranteData`) ya validado, así que podría reutilizarse para importar ficheros. Los rechazos al insertar un dato se lanzan como `LecturaRechazadaException`, que genera siempre el mismo formato de respuesta.

**Contrato por CUPS y fecha.** Un CUPS puede tener varios contratos en el tiempo (por ejemplo, por cambio de titular). La lectura se asigna al contrato vigente en su fecha, con los días de alta y de baja incluidos.

**Contratos de baja.** Siguiendo el enunciado, un contrato en estado `baja` no acepta lecturas, aunque la fecha esté dentro de su vigencia.

**Duplicados y reintentos.** La clave única es `(contrato, fecha_lectura)`:
- Si llega la misma lectura (mismos kWh y tipo), responde `200` con `duplicada: true` y la lectura original. La distribuidora puede reintentar sin miedo.
- Si llega otra distinta para la misma fecha, responde `409` y no sobrescribe. Las peticiones rechazadas (excepto errores de validación y 401) quedan registradas en el log.
- La comprobación de duplicado va antes de las reglas de negocio, para que un reintento de algo ya guardado responda siempre "guardada".
- Un índice único en base de datos cubre el caso de dos reintentos simultáneos.

**Duplicado ya existente en init.sql.** Trae las lecturas 121 y 137 idénticas (contrato 3, 2026-06-01). La migración que crea el índice único borra los duplicados exactos (se queda la de id menor y lo registra en el log). Si encontrara duplicados con valores distintos, no dejaría seguir la migración y pediría que se arreglara antes.

**Coherencia de la lectura.** El contador solo avanza, así que una lectura nueva no puede ser menor que la real anterior ni mayor que la real posterior (por si llega una lectura atrasada). Solo se compara con lecturas **reales**: las estimadas pueden estar desviadas y es la real la que las regulariza. Se admite la vuelta a cero del contador (por ejemplo 99812 → 150) cuando explica el cambio con menos consumo que un retroceso.

**Validaciones adicionales.** Letras de control del CUPS, fecha no futura, valor dentro de los dígitos del contador. Un CUPS de 22 caracteres se asocia al de 20, que es como están guardados los contratos.

**Autenticación.** Token compartido en `Authorization: Bearer`, configurado en `DISTRIBUIDORA_API_TOKEN`. No añado llamada extra (con Sanctum o Passport) para generar token porque solo estamos tratando con una distribuidora.

## Limitaciones y siguientes pasos

Cosas que dejaría para una siguiente versión:

- **Una lectura real en el mismo día que una estimada.** Ahora se rechaza porque ya hay una lectura ese día. Habría que hablarlo: lo lógico sería que la real sustituyera a la estimada, guardando la anterior.
- **Vuelta a cero del contador.** Ahora se acepta con bastante facilidad. Por ejemplo, pasar de 60000 a 5000 lo da por bueno como vuelta, aunque supondría un consumo enorme. Lo mejor sería aceptarla solo si la lectura anterior estaba cerca del máximo del contador. Habría que establecer cuál es el mínimo para poder darle la vuelta.
- **Lecturas de cierre.** Un contrato de baja no admite ninguna lectura, ni siquiera la del último día si llega con retraso. Puede que facturación la necesite para la factura final.
- **Errores inesperados.** Si falla algo que no está previsto (por ejemplo, que se caiga la base de datos), la respuesta no tiene el mismo formato que el resto de errores.
- **Enviar varias lecturas a la vez.** Ahora se envían de una en una. Para la distribuidora sería más cómodo mandar todas las del mes en una sola petición (batch).
- **Consultar lo recibido.** Un endpoint para que la distribuidora pueda comprobar qué lecturas tenemos guardadas de un CUPS.
- **Seguridad.** Con más distribuidoras, cada una debería tener su propio token y que caduque. También convendría limitar el número de peticiones y usar un servidor preparado para producción.
