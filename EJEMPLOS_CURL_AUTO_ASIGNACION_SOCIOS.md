# 🧪 Ejemplos CURL - Auto-asignación de Socios por Profesor

## 🔐 Variables

```bash
BASE_URL="http://localhost:8000/api"
PROFESOR_TOKEN="tu_token_profesor_aqui"
PROFESOR_ID=1
SOCIO_ID=2
SOCIO_ID_2=3
```

---

## 📋 Endpoints de Profesor

### 1️⃣ Listar socios asignados al profesor

```bash
curl -X GET "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"
```

**Respuesta esperada:**
```json
{
  "ok": true,
  "data": {
    "data": [
      {
        "id": 2,
        "dni": "22222222",
        "nombre": "Juan",
        "apellido": "García",
        "name": "Juan García",
        "email": "socio1@test.com",
        "user_type": "api"
      }
    ],
    "total": 1,
    "per_page": 20,
    "current_page": 1
  }
}
```

---

### 2️⃣ Con búsqueda y paginación

```bash
# Buscar por DNI
curl -X GET "${BASE_URL}/profesor/socios?search=22222222" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"

# Buscar por nombre
curl -X GET "${BASE_URL}/profesor/socios?search=Juan" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"

# Con paginación
curl -X GET "${BASE_URL}/profesor/socios?per_page=50&page=2" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"
```

---

### 3️⃣ Listar socios DISPONIBLES (no asignados)

```bash
curl -X GET "${BASE_URL}/profesor/socios/disponibles" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"
```

**Respuesta esperada:**
```json
{
  "ok": true,
  "data": {
    "data": [
      {
        "id": 3,
        "dni": "33333333",
        "nombre": "María",
        "apellido": "López",
        "name": "María López",
        "email": "socio2@test.com",
        "user_type": "api"
      },
      {
        "id": 4,
        "dni": "44444444",
        "nombre": "Carlos",
        "apellido": "Martínez",
        "name": "Carlos Martínez",
        "email": "socio3@test.com",
        "user_type": "api"
      }
    ],
    "total": 2,
    "per_page": 20,
    "current_page": 1
  }
}
```

---

### 4️⃣ Con búsqueda en disponibles

```bash
curl -X GET "${BASE_URL}/profesor/socios/disponibles?search=María" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"
```

---

### 5️⃣ Asignar un socio

```bash
curl -X POST "${BASE_URL}/profesor/socios/${SOCIO_ID}" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json"
```

**Respuesta esperada (201 Created):**
```json
{
  "ok": true,
  "message": "Socio asignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 2,
    "socio": {
      "id": 2,
      "dni": "22222222",
      "nombre": "Juan",
      "apellido": "García",
      "name": "Juan García",
      "email": "socio1@test.com"
    }
  }
}
```

---

### 6️⃣ Asignar múltiples socios (secuencial)

```bash
# Asignar primer socio
curl -X POST "${BASE_URL}/profesor/socios/2" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"

# Esperar respuesta

# Asignar segundo socio
curl -X POST "${BASE_URL}/profesor/socios/3" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"
```

---

### 7️⃣ Desasignar un socio

```bash
curl -X DELETE "${BASE_URL}/profesor/socios/${SOCIO_ID}" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" \
  -H "Accept: application/json"
```

**Respuesta esperada:**
```json
{
  "ok": true,
  "message": "Socio desasignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 2
  }
}
```

---

## ❌ Casos de Error

### Error 401 - No autenticado

```bash
# Sin token
curl -X GET "${BASE_URL}/profesor/socios"
```

**Respuesta:**
```json
{
  "message": "Unauthenticated."
}
```

---

### Error 403 - Usuario no es profesor

```bash
# Con token de estudiante (is_profesor = false)
curl -X GET "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${ESTUDIANTE_TOKEN}"
```

**Respuesta:**
```json
{
  "message": "Solo profesores pueden acceder a esta ruta"
}
```

---

### Error 422 - Socio ya asignado

```bash
# Intentar asignar un socio que ya está asignado
curl -X POST "${BASE_URL}/profesor/socios/2" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}"
```

**Respuesta:**
```json
{
  "ok": false,
  "message": "El socio ya está asignado a este profesor"
}
```

---

### Error 422 - Usuario no es socio (no es API)

```bash
# Intentar asignar un usuario local
curl -X POST "${BASE_URL}/profesor/socios/10" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}"
```

**Respuesta:**
```json
{
  "message": "El usuario debe ser un socio (API)"
}
```

---

### Error 404 - Socio no asignado

```bash
# Intentar desasignar un socio que no está asignado
curl -X DELETE "${BASE_URL}/profesor/socios/5" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}"
```

**Respuesta:**
```json
{
  "message": "El socio no está asignado a este profesor"
}
```

---

## 📊 Flujo Completo de Prueba

```bash
#!/bin/bash

BASE_URL="http://localhost:8000/api"
PROFESOR_TOKEN="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."

echo "🔍 1. Listar socios asignados (debe estar vacío)"
curl -s -X GET "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n🔍 2. Listar socios disponibles (debe haber varios)"
curl -s -X GET "${BASE_URL}/profesor/socios/disponibles" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n➕ 3. Asignar primer socio"
curl -s -X POST "${BASE_URL}/profesor/socios/2" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n➕ 4. Asignar segundo socio"
curl -s -X POST "${BASE_URL}/profesor/socios/3" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n🔍 5. Listar socios asignados (debe haber 2)"
curl -s -X GET "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n🔍 6. Listar socios disponibles (debe haber menos)"
curl -s -X GET "${BASE_URL}/profesor/socios/disponibles" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n❌ 7. Desasignar primer socio"
curl -s -X DELETE "${BASE_URL}/profesor/socios/2" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .

echo -e "\n🔍 8. Verificar que quedó solo 1 socio asignado"
curl -s -X GET "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq .
```

---

## 📌 Endpoints ADMIN (No cambian)

```bash
# Admin: Listar todos los profesores
curl -X GET "${BASE_URL}/admin/profesores" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}"

# Admin: Listar todos los socios
curl -X GET "${BASE_URL}/admin/socios" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}"

# Admin: Ver socios de un profesor específico
curl -X GET "${BASE_URL}/admin/profesores/1/socios" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}"

# Admin: Asignar múltiples socios a un profesor
curl -X POST "${BASE_URL}/admin/profesores/1/socios" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "socio_ids": [2, 3, 4, 5]
  }'
```

---

## 💡 Tips

### Con jq (parseo bonito)
```bash
curl -s "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | jq '.'
```

### Con pretty-print
```bash
curl "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" | python -m json.tool
```

### Guardar respuesta en archivo
```bash
curl "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}" > response.json
```

### Ver headers de respuesta
```bash
curl -i "${BASE_URL}/profesor/socios" \
  -H "Authorization: Bearer ${PROFESOR_TOKEN}"
```

---

## 🚀 Bash Script para Obtener Token de Prueba

```bash
#!/bin/bash

BASE_URL="http://localhost:8000/api"
EMAIL="profesor@test.com"
PASSWORD="password"

echo "🔐 Obteniendo token..."

TOKEN=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"${EMAIL}\",
    \"password\": \"${PASSWORD}\"
  }" | jq -r '.data.token')

echo "Token obtenido: $TOKEN"
echo "Usar en otros comandos como:"
echo "  -H \"Authorization: Bearer $TOKEN\""
```

