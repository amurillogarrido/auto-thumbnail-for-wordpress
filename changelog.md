# Changelog - Auto Thumbnail for WordPress

Todos los cambios notables en este proyecto serán documentados en este archivo.

---

## [1.0.9 Mejorada] - Enero 2026

### ✨ Nuevas Funcionalidades

#### Crop Centrado Automático
- **Añadido:** Función de crop centrado para redimensionar imágenes al tamaño exacto deseado
- **Añadido:** Opción para activar/desactivar crop en ajustes
- **Añadido:** Campos configurables para ancho y alto de la imagen final
- **Valor por defecto:** 1200x630 píxeles (óptimo para redes sociales)
- **Resultado:** Todas las imágenes destacadas tienen exactamente el mismo tamaño sin deformaciones

#### Marco Interior
- **Añadido:** Nueva sección "Marco Interior" en ajustes
- **Añadido:** Opción para activar/desactivar marco decorativo
- **Añadido:** Selector de color del marco (blanco por defecto)
- **Añadido:** Control de grosor del marco en píxeles (3px por defecto)
- **Añadido:** Control de margen/separación del marco (40px por defecto)
- **Aplicación:** El marco se dibuja tanto en imágenes de Google como en imágenes de respaldo

#### Imagen de Respaldo
- **Añadido:** Sistema completo de imagen de respaldo cuando Google no encuentra resultados
- **Añadido:** Opción para activar/desactivar imagen de respaldo en ajustes
- **Funcionamiento:** Genera imagen desde cero con color de fondo y título
- **Características:** Aplica filtros, marco y estilos configurados
- **Dimensiones:** Usa las mismas dimensiones configuradas para el crop
- **Activado por defecto:** Para asegurar que siempre hay imagen destacada

### 🔧 Correcciones de Bugs

#### Problema de Doble Codificación en URLs
- **Corregido:** URLs de búsqueda de Google se codificaban dos veces
- **Síntoma:** Títulos con apóstrofes, eñes u otros caracteres especiales generaban URLs rotas
- **Ejemplo del error:** `%2526%25238217%253Bs` en lugar de `'s`
- **Solución:** Eliminado `urlencode()` manual ya que `http_build_query()` ya codifica automáticamente
- **Resultado:** Búsquedas funcionan correctamente con cualquier carácter especial

### 🎨 Mejoras en Funcionalidades Existentes

#### Sistema de Procesamiento de Imágenes
- **Mejorado:** La función `process_image_overlay()` ahora también aplica el marco
- **Mejorado:** Las imágenes de respaldo usan la misma configuración que las imágenes de Google
- **Mejorado:** Mejor integración entre crop, overlay, filtros y marco

#### Interfaz de Administración
- **Añadido:** Nueva sección "Dimensiones y Crop" en el panel de ajustes
- **Añadido:** Nueva sección "Marco Interior" en el panel de ajustes
- **Mejorado:** Descripciones más claras en todos los campos
- **Mejorado:** Valores recomendados visibles en las descripciones
- **Añadido:** Información sobre uso del color de fondo en imágenes de respaldo

#### Sistema de Logs
- **Mejorado:** Mensajes más descriptivos cuando se genera imagen de respaldo
- **Mejorado:** Información clara sobre cuando se aplica crop centrado
- **Mejorado:** Mejor seguimiento del proceso completo de generación

### 📝 Cambios en Valores por Defecto

```php
// NUEVOS valores por defecto añadidos:
'agt_fallback_enable'  => 1,     // Imagen de respaldo activada
'agt_crop_enable'      => 1,     // Crop centrado activado
'agt_crop_width'       => 1200,  // Ancho estándar
'agt_crop_height'      => 630,   // Alto estándar (ratio 1.91:1)
'agt_frame_enable'     => 0,     // Marco desactivado por defecto
'agt_frame_color'      => '#FFFFFF', // Blanco
'agt_frame_width'      => 3,     // 3 píxeles
'agt_frame_margin'     => 40,    // 40 píxeles de margen
```

### 🏗️ Cambios Técnicos Internos

#### Nuevas Funciones
- `crop_image_centered()` - Aplica crop centrado a imágenes de Google
- `generate_fallback_image()` - Genera imagen de respaldo desde cero
- `apply_frame()` - Dibuja marco interior en la imagen

#### Flujo de Procesamiento Actualizado
1. Búsqueda en Google Imágenes
2. Si no encuentra → `generate_fallback_image()`
3. Si encuentra → Descarga imagen
4. **NUEVO:** Aplica `crop_image_centered()` si está activado
5. Aplica `process_image_overlay()` (filtros, overlay, texto, **marco**)
6. Guarda e importa a WordPress

---

## [1.0.8] - Diciembre 2025

### Funcionalidades Base
- Búsqueda automática en Google Imágenes
- Filtros de búsqueda (derechos, tamaño, formato, tipo)
- Overlay oscuro con texto del título
- Filtro de blanco y negro
- Lista negra de dominios
- Generación en lote
- Registro de actividad
- Selección de fuentes personalizadas
- Control de opacidad y colores

---

## 🔮 Futuras Mejoras (Roadmap)

### En consideración para próximas versiones:
- [ ] Plantillas predefinidas de diseño (minimalista, moderno, corporativo)
- [ ] Previsualización en tiempo real antes de publicar
- [ ] Generación de variantes para diferentes redes sociales
- [ ] Marca de agua / logotipo automático
- [ ] Gradientes en lugar de colores sólidos
- [ ] Texto adicional configurable (subtítulo, categoría, fecha)
- [ ] Dashboard con estadísticas
- [ ] Regeneración masiva solo de imágenes de respaldo

---

## 📊 Estadísticas de la Versión Actual

- **Líneas de código:** ~700 (archivo principal)
- **Nuevas funciones:** 3
- **Nuevas opciones de configuración:** 8
- **Bugs corregidos:** 1 crítico
- **Compatibilidad:** WordPress 5.0+ / PHP 7.4+

---

## 🙌 Contribuciones

Si deseas contribuir al proyecto:
1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/NuevaFuncionalidad`)
3. Commit tus cambios (`git commit -m 'Añadir nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/NuevaFuncionalidad`)
5. Abre un Pull Request

---

**Mantenido por:** Alberto Murillo
**Repositorio:** https://github.com/amurillogarrido/auto-thumbnail-for-wordpress