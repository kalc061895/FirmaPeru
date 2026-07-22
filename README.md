## 🚀 Despliegue en Servidor (Producción / Local)

Sigue estos pasos para desplegar la aplicación en un nuevo servidor mediante Docker.

### Prerrequisitos
- Tener instalado **Git**, **Docker** y **Docker Compose** en el servidor.
- Asegurarse de que el puerto elegido (ej. `8080` u otro) esté abierto en el Firewall/UFW del servidor.
-- 

---

### Paso 1: Clonar el Repositorio

Acede al directorio del servidor donde alojarás la aplicación y clona el proyecto:

```bash
git clone <URL_DE_TU_REPOSITO_GIT> sgd-firmas
cd sgd-firmas
```

---

### Paso 2: Crear Archivo de Entorno y Directorios Persistentes

Para evitar que Docker cree la base de datos o carpeta como directorios vacíos de root, ejecuta los siguientes comandos de inicialización:

```bash
# 1. Crear el archivo SQLite base si no existe
touch db_sgd_firmas.sqlite

# 2. Crear la carpeta donde se almacenarán los PDFs
mkdir -p archivos_sgd

# 3. Dar permisos de escritura para el contenedor
chmod 777 db_sgd_firmas.sqlite
chmod -R 777 archivos_sgd
```

---

### Paso 3: Configurar el Archivo de Entorno (.env)

Copia la plantilla de entorno y asigna el puerto y credenciales de la institución:

```bash
cp .env.example .env
nano .env # o vi .env

```

---

### Paso 4: Levantar el Contenedor

Compila e inicia los servicios en segundo plano:

```bash
docker compose up -d --build
```

Para verificar que el contenedor se esté ejecutando correctamente:

```bash
docker compose ps
```

---

### Paso 5: Probar el Acceso

Abre tu navegador e ingresa a:
`http://<IP_DEL_SERVIDOR>:<PUERTO>`

Ejemplo: `http://192.168.1.50:8080`

---

## 🔄 Actualizaciones Futuras (Pase a Producción)

Cuando realices cambios en el código y los subas a Git, para actualizar el servidor solo ejecuta:

```bash
git pull origin main
docker compose up -d --build
```
*(Tus PDFs subidos y la base de datos SQLite no se borrarán porque están respaldados en los volúmenes del host).*