# Manager Library — Backend API (Laravel 11)

> **Prueba técnica · Ficha ADSO 3413974**  
> Backend del sistema de gestión bibliotecaria desarrollado en **PHP 8.2** con **Laravel 11**, bajo el enfoque **Spec-Driven Development (SDD)** sobre **PostgreSQL 16**.

---

## 1. Arquitectura y Principios de Diseño

El backend implementa los principios innegociables de la constitución del proyecto y adapta los patrones de **Arquitectura Hexagonal (Puertos y Adaptadores)** al ecosistema de Laravel:

* **Separación de capas e inmutabilidad:**
  * **Adaptadores de entrada (HTTP):** Controladores en `app/Http/Controllers/Api/` que manejan las peticiones REST, validan entradas y transforman los resultados al contrato JSON estricto (`snake_case`, UUID v4, fechas ISO 8601 en UTC con `Z`).
  * **Lógica de Dominio:** Servicios y modelos que encapsulan las reglas de negocio (plazo de 14 días para préstamos, límite de 3 libros activos por socio, bloqueo de préstamos si existen libros en mora, y cálculo en tiempo de ejecución de estados y días de retraso).
  * **Adaptadores de salida (Persistencia):** Modelos Eloquent y consultas transaccionales atómicas conectadas al esquema `library` en PostgreSQL.
* **Art. V & ADR-001 (Propiedad del Esquema):** Todo el DDL, claves foráneas, restricciones `CHECK` e índices únicos parciales residen en las migraciones de Laravel (`database/migrations/`).
* **Art. VI (Invariantes en el Motor):** Restricciones declaradas en base de datos:
  * `ck_book_available_range`: `0 <= available_copies <= total_copies`
  * `ck_book_total_positive`: `total_copies >= 1`
  * `ck_user_role`: `role IN ('admin', 'librarian')`
* **Art. VII (Lo derivado se calcula, no se almacena):** Ni la tabla `loan` ni `loan_item` almacenan columnas `status` o `days_late`. Los estados `open`, `overdue` y `closed` se derivan dinámicamente en tiempo de ejecución.
* **Art. VIII & ADR-004 (Valores Congelados):** En `loan_item` se congelan `book_title`, `isbn` y `category_name` en el instante del préstamo. Esto preserva la fidelidad histórica del catálogo aunque el libro o la categoría se modifiquen posteriormente.
* **Art. IX (Cero Secretos):** Contraseñas hasheadas con **Argon2id**; jamás se exponen hashes ni claves en logs o respuestas JSON.
* **ADR-002 (Concurrencia Optimista):** Deducción condicional atómica en una sola transacción (`where available_copies >= 1`) con respaldo del `CHECK` a nivel de base de datos.
* **ADR-003 (Baja Lógica):** `DELETE /books/{id}` realiza borrado lógico (`deleted_at`). Si el libro tiene préstamos abiertos, responde `409 BOOK_HAS_OPEN_LOANS`.
* **ADR-005 (Gestión de Bibliotecarios y Compatibilidad):** Listado y baja de bibliotecarios respetando la integridad referencial (`ON DELETE RESTRICT` de préstamos históricos), y compatibilidad para subida de archivos multipart en entornos PHP.

---

## 2. Catálogo de Endpoints de la API (`/api/v1`)

Todos los endpoints (salvo `/health` y `/auth/login`) requieren autenticación mediante encabezado `Authorization: Bearer <token>`.

### 2.1 Salud y Autenticación
| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `GET` | `/health` | Público | Verificación de salud del servicio (`{"status": "ok"}`) |
| `POST` | `/auth/login` | Público | Autenticación de usuario. Devuelve JWT válido por 8 horas |

### 2.2 Usuarios y Bibliotecarios (Admin)
| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `GET` | `/users` | `admin` | Lista bibliotecarios activos |
| `POST` | `/users` | `admin` | Crea un bibliotecario (`username`, `password`, `role: 'librarian'`) |
| `DELETE` | `/users/{id}` | `admin` | Da de baja a un bibliotecario si no tiene préstamos registrados en el histórico |

### 2.3 Categorías y Catálogo de Libros
| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `GET` | `/categories` | Autenticado | Lista las 5 categorías oficiales ordenadas por nombre |
| `GET` | `/books` | Autenticado | Catálogo paginado con búsqueda por título/autor (`q`) y filtro por categoría |
| `GET` | `/books/{id}` | Autenticado | Detalle de un libro específico |
| `POST` | `/books` | `admin` | Crea un nuevo libro (ISBN-13 único, total de ejemplares ≥ 1) |
| `PUT` | `/books/{id}` | `admin` | Modifica título, autor, categoría o total de ejemplares |
| `DELETE` | `/books/{id}` | `admin` | Baja lógica del libro (impide baja si tiene préstamos abiertos) |
| `GET` | `/books/{id}/cover` | Autenticado | Descarga la imagen binaria de la portada |
| `PUT/POST` | `/books/{id}/cover` | `admin` | Sube la portada (JPG/PNG, máx. 2 MB; admite PUT directo o POST con spoofing) |
| `DELETE` | `/books/{id}/cover` | `admin` | Elimina la portada del libro |

### 2.4 Socios
| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `GET` | `/members` | Autenticado | Lista y búsqueda de socios con paginación |
| `GET` | `/members/{id}` | Autenticado | Detalle del socio incluyendo conteo de `active_loans` |
| `POST` | `/members` | Autenticado | Registra un nuevo socio con documento único |

### 2.5 Préstamos y Devoluciones
| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `POST` | `/loans` | Autenticado | Registra préstamo de 1 a 3 líneas con validación de límite y mora |
| `GET` | `/loans` | Autenticado | Lista préstamos con filtros, incluyendo datos enriquecidos del socio |
| `GET` | `/loans/{id}` | Autenticado | Detalle completo del préstamo con líneas, estados y socio solicitante |
| `POST` | `/loans/{id}/items/{item_id}/return` | Autenticado | Devolución atómica de una línea del préstamo |
| `GET` | `/loans/overdue` | Autenticado | Lista de líneas vencidas ordenadas por días de retraso |

### 2.6 Reportes
| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `GET` | `/reports/most-borrowed` | `admin` | Reporte de libros más prestados por rango de fechas (congelación histórica) |

---

## 3. Manejo de Errores Unificado

Todos los errores del sistema responden bajo la estructura estricta del contrato:

```json
{
  "code": "BOOK_NOT_AVAILABLE",
  "message": "No quedan ejemplares disponibles de «Cien años de soledad».",
  "details": [
    { "field": "items[0].book_id", "issue": "sin ejemplares" }
  ]
}
```

Códigos de negocio implementados:
* `INVALID_CREDENTIALS` (401), `FORBIDDEN_ROLE` (403), `NOT_FOUND` (404)
* `USERNAME_TAKEN` (409), `ISBN_TAKEN` (409), `DOCUMENT_TAKEN` (409)
* `BOOK_HAS_OPEN_LOANS` (409), `BOOK_NOT_AVAILABLE` (409), `MEMBER_HAS_OVERDUE` (409), `ITEM_ALREADY_RETURNED` (409), `FORBIDDEN_OPERATION` (409)
* `MEMBER_LIMIT_EXCEEDED` (422), `DUPLICATE_BOOK_IN_LOAN` (422), `INVALID_DATE_RANGE` (422), `VALIDATION_ERROR` (422).

---

## 4. Ejecución y Pruebas

### Vía Docker Compose (Recomendado):
El servicio corre en el contenedor `library_api` en el puerto `8000`:
```bash
docker compose -f test-manager-library-3413974-infra/docker-compose.yml up -d --build api
```

### Ejecutar Pruebas Automatizadas:
```bash
docker compose -f test-manager-library-3413974-infra/docker-compose.yml exec api php artisan test
```
