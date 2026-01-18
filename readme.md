# Auto Thumbnail for WordPress - Versión Mejorada

Plugin de WordPress que genera automáticamente imágenes destacadas desde Google Imágenes o crea imágenes de respaldo con el título del post.

## 🆕 NOVEDADES EN ESTA VERSIÓN

### ✅ Crop Centrado Automático
- **Nueva funcionalidad:** Redimensiona y recorta cualquier imagen de Google para que tenga siempre el tamaño exacto configurado
- **Configurable:** Puedes elegir las dimensiones finales (por defecto 1200x630px)
- **Activación:** Se puede activar/desactivar desde los ajustes
- **Resultado:** Todas las imágenes destacadas tendrán exactamente el mismo tamaño, sin deformaciones

### ✅ Marco Interior Elegante
- **Nueva funcionalidad:** Añade un marco decorativo alrededor de la imagen
- **Totalmente personalizable:** Color, grosor y margen configurables
- **Activación:** Se puede activar/desactivar desde los ajustes
- **Ideal para:** Diarios online, blogs profesionales, medios de comunicación

### ✅ Imagen de Respaldo
- **Nueva funcionalidad:** Si no se encuentra imagen en Google, genera una imagen con fondo de color y título
- **Configurable:** Usa los mismos colores y fuentes que el overlay
- **Resultado:** Siempre tendrás una imagen destacada, aunque Google no devuelva resultados

### 🔧 Corrección de Bugs
- **Arreglado:** Problema de doble codificación en URLs de búsqueda de Google
- **Arreglado:** URLs con caracteres especiales (apóstrofes, eñes, etc.) ahora funcionan correctamente

---

## 📥 INSTALACIÓN

1. Descomprime el archivo ZIP
2. Sube la carpeta `auto-thumbnail-for-wordpress` a `/wp-content/plugins/`
3. Activa el plugin desde el panel de WordPress
4. Ve a **Auto Thumbnail → Ajustes** para configurarlo

---

## ⚙️ CONFIGURACIÓN RECOMENDADA

### Para un diario online profesional:

**Ajustes Generales:**
- ✅ Activar Plugin: **SÍ**
- ✅ Generar Imagen de Respaldo: **SÍ**
- Idioma de Búsqueda: **Español**

**Filtros de Búsqueda:**
- Derechos de Uso: **Reutilización con modificación**
- Tipo de Archivo: **JPG**
- Formato de Imagen: **Horizontal**
- Tamaño Mínimo: **Mayor de 800x600**

**Dimensiones y Crop:**
- ✅ Activar Crop Centrado: **SÍ**
- Ancho de Imagen: **1200px**
- Alto de Imagen: **630px**

**Edición de Imagen (Filtros y Texto):**
- ✅ Activar Superposición de Texto: **SÍ**
- Color de Fondo (Capa): **#000000** (negro)
- Opacidad del Fondo: **80%**
- Color del Texto: **#FFFFFF** (blanco)
- Fuente: **Roboto**
- Tamaño de Fuente: **55px**

**Marco Interior:**
- ✅ Activar Marco: **SÍ**
- Color del Marco: **#FFFFFF** (blanco)
- Grosor del Marco: **3px**
- Margen del Marco: **40px**

---

## 📁 ESTRUCTURA DE ARCHIVOS

```
auto-thumbnail-for-wordpress/
├── auto-google-thumbnail.php    (Archivo principal del plugin)
├── admin-settings.php           (Panel de administración)
├── bulk-generate.php            (Generación masiva)
├── fonts/                       (Carpeta de fuentes)
│   ├── Roboto.ttf
│   └── Source.ttf
├── README.md                    (Este archivo)
└── CHANGELOG.md                 (Historial de cambios)
```

---

## 🎨 FUNCIONALIDADES PRINCIPALES

### 1. Búsqueda Automática en Google Imágenes
- Busca automáticamente imágenes relacionadas con el título del post
- Múltiples filtros disponibles (tamaño, formato, derechos de uso, etc.)
- Lista negra de dominios para excluir sitios no deseados (ej: Pinterest)

### 2. Crop Centrado
- Redimensiona imágenes de Google al tamaño exacto configurado
- Recorta el centro automáticamente
- Mantiene la proporción óptima
- Sin deformaciones

### 3. Overlay de Texto
- Oscurece la imagen con una capa semitransparente
- Escribe el título del post centrado
- Color, opacidad y fuente personalizables
- Ajuste automático de líneas según longitud del título

### 4. Marco Interior
- Añade un borde elegante alrededor de la imagen
- Color y grosor personalizables
- Perfecto para dar un aspecto profesional

### 5. Imagen de Respaldo
- Si no se encuentra imagen en Google, genera una desde cero
- Usa el color de fondo configurado
- Incluye el título centrado
- Se le puede aplicar el marco también

### 6. Filtros de Imagen
- Blanco y negro (escala de grises)
- Se puede combinar con overlay y marco

### 7. Generación en Lote
- Procesa múltiples posts a la vez
- Interfaz visual con progreso en tiempo real
- Útil para aplicar imágenes destacadas a posts antiguos

### 8. Registro de Actividad
- Log detallado de todas las operaciones
- Útil para depurar problemas
- Muestra éxitos, errores e información

---

## 🛡️ CONSIDERACIONES LEGALES

### Imágenes de Google
- **IMPORTANTE:** Este plugin descarga imágenes de Google Imágenes
- Se recomienda usar los filtros de "Derechos de Uso" para buscar solo imágenes con licencia
- Con overlay al 80% de opacidad, la imagen original queda muy oscurecida (menor riesgo legal)
- Para máxima seguridad, usa la opacidad al 85-90%

### Imagen de Respaldo
- Las imágenes de respaldo son creadas desde cero por el plugin
- No tienen problemas de copyright
- Son 100% seguras de usar

---

## 🔧 REQUISITOS TÉCNICOS

- WordPress 5.0 o superior
- PHP 7.4 o superior
- Librería GD de PHP (para procesamiento de imágenes)
- Permisos de escritura en la carpeta de uploads

---

## 📞 SOPORTE

Si tienes problemas o preguntas:
1. Revisa el **Registro de Actividad** en el plugin
2. Verifica que tienes instalada la librería GD de PHP
3. Asegúrate de que las fuentes .ttf están en la carpeta `/fonts/`

---

## 👨‍💻 AUTOR

**Alberto Murillo**
- Web: https://albertomurillo.pro/
- GitHub: https://github.com/amurillogarrido/auto-thumbnail-for-wordpress

---

## 📝 LICENCIA

GPL-2.0+
http://www.gnu.org/licenses/gpl-2.0.txt

---

## 🙏 AGRADECIMIENTOS

Gracias por usar Auto Thumbnail for WordPress. Si te resulta útil, considera dejar una valoración o compartirlo.

---

**Versión actual:** 1.0.9 (Mejorada)
**Última actualización:** Enero 2026