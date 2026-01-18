<?php
if ( ! defined( 'WPINC' ) ) die;

class AGT_Admin_Pages {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_agt_clear_log', array( $this, 'clear_log_action' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Auto Google Thumbnail',   
            'Auto Thumbnail',          
            'manage_options',          
            'auto-google-thumbnail',   
            array($this, 'render_settings_page'),
            'dashicons-format-image'  
        );

        add_submenu_page(
            'auto-google-thumbnail',
            'Ajustes',
            'Ajustes',
            'manage_options',
            'auto-google-thumbnail', 
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'auto-google-thumbnail',
            'Registro de Actividad',
            'Registro de Actividad',
            'manage_options',
            'agt-activity-log',
            array($this, 'render_log_page')
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Ajustes de Auto Google Thumbnail</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'agt_settings_group' );
                do_settings_sections( 'auto-google-thumbnail-settings' );
                submit_button( 'Guardar Cambios' );
                ?>
            </form>
        </div>
        <?php
    }

    public function render_log_page() {
        $log = get_option('agt_activity_log', []);
        ?>
        <div class="wrap">
            <h1>Registro de Actividad</h1>
            <p>Aquí se muestran las últimas 100 acciones realizadas por el plugin. Es muy útil para depurar por qué una imagen no se ha generado.</p>
            
            <form action="admin-post.php" method="post" style="margin-bottom: 20px;">
                <input type="hidden" name="action" value="agt_clear_log">
                <?php wp_nonce_field('agt_clear_log_nonce'); ?>
                <?php submit_button('Limpiar Registro', 'delete', 'clear-log-submit', false); ?>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 180px;">Fecha y Hora</th>
                        <th style="width: 100px;">Tipo</th>
                        <th>Mensaje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($log)) : ?>
                        <tr><td colspan="3">El registro está vacío.</td></tr>
                    <?php else : ?>
                        <?php foreach ($log as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html($entry['date']); ?></td>
                                <td>
                                    <?php
                                    $color = '#2271b1';
                                    if ($entry['type'] === 'SUCCESS') $color = '#00a32a';
                                    if ($entry['type'] === 'ERROR') $color = '#d63638';
                                    ?>
                                    <strong style="color: <?php echo $color; ?>;"><?php echo esc_html($entry['type']); ?></strong>
                                </td>
                                <td><?php echo esc_html($entry['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function clear_log_action() {
        if ( isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'agt_clear_log_nonce') && current_user_can('manage_options') ) {
            delete_option('agt_activity_log');
        }
        wp_redirect(admin_url('admin.php?page=agt-activity-log'));
        exit;
    }

    public function register_settings() {
        register_setting( 'agt_settings_group', 'agt_settings' );

        add_settings_section( 'agt_general_section', 'Ajustes Generales', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_enable_field', 'Activar Plugin', array( $this, 'render_enable_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_selection_field', 'Selección de Imagen', array( $this, 'render_selection_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_language_field', 'Idioma de Búsqueda', array( $this, 'render_language_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_search_engine_field', 'Motor de Búsqueda', array( $this, 'render_search_engine_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_bing_api_key_field', 'Bing API Key', array( $this, 'render_bing_api_key_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_fallback_enable_field', 'Generar Imagen de Respaldo', array( $this, 'render_fallback_enable_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        
        add_settings_section( 'agt_filter_section', 'Filtros de Búsqueda', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_rights_field', 'Derechos de Uso', array( $this, 'render_rights_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_filetype_field', 'Tipo de Archivo (Extensión)', array( $this, 'render_filetype_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_format_field', 'Formato de Imagen', array( $this, 'render_format_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_size_field', 'Tamaño Mínimo', array( $this, 'render_size_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_type_field', 'Tipo de Imagen', array( $this, 'render_type_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_blacklist_field', 'Lista Negra de Dominios', array( $this, 'render_blacklist_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );

        // --- NUEVA SECCIÓN: DIMENSIONES Y CROP ---
        add_settings_section( 'agt_crop_section', 'Dimensiones y Crop', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_crop_enable_field', 'Activar Crop Centrado', array( $this, 'render_crop_enable_field' ), 'auto-google-thumbnail-settings', 'agt_crop_section' );
        add_settings_field( 'agt_crop_width_field', 'Ancho de Imagen (px)', array( $this, 'render_crop_width_field' ), 'auto-google-thumbnail-settings', 'agt_crop_section' );
        add_settings_field( 'agt_crop_height_field', 'Alto de Imagen (px)', array( $this, 'render_crop_height_field' ), 'auto-google-thumbnail-settings', 'agt_crop_section' );

        // --- SECCIÓN: EDICIÓN DE IMAGEN (FILTROS Y OVERLAY) ---
        add_settings_section( 'agt_overlay_section', 'Edición de Imagen (Filtros y Texto)', null, 'auto-google-thumbnail-settings' );
        
        add_settings_field( 'agt_grayscale_enable_field', 'Filtro Blanco y Negro', array( $this, 'render_grayscale_enable_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );
        
        add_settings_field( 'agt_overlay_enable_field', 'Activar Superposición de Texto', array( $this, 'render_overlay_enable_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );
        add_settings_field( 'agt_overlay_bg_color_field', 'Color de Fondo (Capa)', array( $this, 'render_overlay_bg_color_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );
        add_settings_field( 'agt_overlay_opacity_field', 'Opacidad del Fondo (%)', array( $this, 'render_overlay_opacity_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );
        add_settings_field( 'agt_overlay_text_color_field', 'Color del Texto', array( $this, 'render_overlay_text_color_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );
        add_settings_field( 'agt_overlay_font_family_field', 'Fuente (Carpeta /fonts/)', array( $this, 'render_overlay_font_family_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );
        add_settings_field( 'agt_overlay_font_size_field', 'Tamaño de Fuente', array( $this, 'render_overlay_font_size_field' ), 'auto-google-thumbnail-settings', 'agt_overlay_section' );

        // --- NUEVA SECCIÓN: MARCO ---
        add_settings_section( 'agt_frame_section', 'Marco Interior', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_frame_enable_field', 'Activar Marco', array( $this, 'render_frame_enable_field' ), 'auto-google-thumbnail-settings', 'agt_frame_section' );
        add_settings_field( 'agt_frame_color_field', 'Color del Marco', array( $this, 'render_frame_color_field' ), 'auto-google-thumbnail-settings', 'agt_frame_section' );
        add_settings_field( 'agt_frame_width_field', 'Grosor del Marco (px)', array( $this, 'render_frame_width_field' ), 'auto-google-thumbnail-settings', 'agt_frame_section' );
        add_settings_field( 'agt_frame_margin_field', 'Margen del Marco (px)', array( $this, 'render_frame_margin_field' ), 'auto-google-thumbnail-settings', 'agt_frame_section' );
    }
    
    public function render_enable_field() {
        $options = get_option('agt_settings');
        $checked = $options['agt_enable'] ?? 0;
        echo '<input type="checkbox" name="agt_settings[agt_enable]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<p class="description">Marcar para activar la generación automática.</p>';
    }

    public function render_selection_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_selection'] ?? 'first';
        $selections = [ 'Primera' => 'first', 'Mejor Resolución' => 'best' ];
        echo '<select name="agt_settings[agt_selection]">';
        foreach ( $selections as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_language_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_language'] ?? 'es';
        $languages = [ 'Español' => 'es', 'Inglés' => 'en', 'Francés' => 'fr', 'Alemán' => 'de', 'Italiano' => 'it', 'Portugués' => 'pt', 'Catalán' => 'ca', 'Vasco' => 'eu', 'Gallego' => 'gl' ];
        echo '<select name="agt_settings[agt_language]">';
        foreach ( $languages as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_fallback_enable_field() {
        $options = get_option('agt_settings');
        $checked = $options['agt_fallback_enable'] ?? 1;
        echo '<input type="checkbox" name="agt_settings[agt_fallback_enable]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<p class="description">Si no se encuentra ninguna imagen en Google, genera una imagen con solo el título y el color de fondo configurado.</p>';
    }

    public function render_rights_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_rights'] ?? 'fmc';
        $rights = [ 'Reutilización con modificación' => 'fmc', 'Reutilización' => 'fc', 'Reutilización no comercial con modificación' => 'fm', 'Reutilización no comercial' => 'f', 'Sin filtrar (¡NO RECOMENDADO!)' => '' ];
        echo '<select name="agt_settings[agt_rights]">';
        foreach ( $rights as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }
    
    public function render_filetype_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_filetype'] ?? 'all';
        $filetypes = [
            'Cualquier tipo' => 'all',
            'JPG' => 'jpg',
            'PNG' => 'png',
            'WEBP' => 'webp'
        ];
        echo '<select name="agt_settings[agt_filetype]">';
        foreach ( $filetypes as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Filtra los resultados para obtener un tipo de archivo de imagen específico.</p>';
    }

    public function render_format_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_format'] ?? '';
        $formats = [ 'Cualquier formato' => '', 'Horizontal' => 'w', 'Vertical' => 't', 'Cuadrada' => 's', 'Panorámica' => 'xw' ];
        echo '<select name="agt_settings[agt_format]">';
        foreach ( $formats as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_size_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_size'] ?? '';
        $sizes = [ 'Cualquier tamaño' => '', 'Grande' => 'l', 'Mediana' => 'm', 'Icono' => 'i', 'Mayor de 800x600' => 'lt,islt:svga', 'Mayor de 1024x768' => 'lt,islt:xga', 'Mayor de 2 MP' => 'lt,islt:2mp', 'Mayor de 4 MP' => 'lt,islt:4mp' ];
        echo '<select name="agt_settings[agt_size]">';
        foreach ( $sizes as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_type_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_type'] ?? '';
        $types = [ 'Cualquier tipo' => '', 'Fotografía' => 'photo', 'Clipart' => 'clipart', 'Dibujo lineal' => 'lineart', 'Rostro' => 'face', 'Animada (GIF)' => 'animated' ];
        echo '<select name="agt_settings[agt_type]">';
        foreach ( $types as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_blacklist_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_blacklist'] ?? '';
        echo '<textarea name="agt_settings[agt_blacklist]" rows="5" cols="50" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">Introduce los dominios a excluir de la búsqueda (uno por línea o separados por comas). Ejemplo: <code>pinterest.com, 123rf.com</code></p>';
    }

    // --- NUEVAS FUNCIONES: CROP ---

    public function render_crop_enable_field() {
        $options = get_option('agt_settings');
        $checked = $options['agt_crop_enable'] ?? 1;
        echo '<input type="checkbox" name="agt_settings[agt_crop_enable]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<p class="description">Redimensiona y recorta las imágenes de Google para que tengan siempre el tamaño exacto configurado abajo. Recomendado para uniformidad visual.</p>';
    }

    public function render_crop_width_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_crop_width'] ?? '1200';
        echo '<input type="number" name="agt_settings[agt_crop_width]" value="' . esc_attr($value) . '" min="100" max="3000" step="10" />';
        echo '<p class="description">Ancho final de la imagen en píxeles. Recomendado: 1200px para redes sociales.</p>';
    }

    public function render_crop_height_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_crop_height'] ?? '630';
        echo '<input type="number" name="agt_settings[agt_crop_height]" value="' . esc_attr($value) . '" min="100" max="3000" step="10" />';
        echo '<p class="description">Alto final de la imagen en píxeles. Recomendado: 630px para redes sociales (ratio 1.91:1).</p>';
    }

    // --- FUNCIONES DE RENDERIZADO (OVERLAY) ---

    public function render_grayscale_enable_field() {
        $options = get_option('agt_settings');
        $checked = $options['agt_grayscale_enable'] ?? 0;
        echo '<input type="checkbox" name="agt_settings[agt_grayscale_enable]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<p class="description">Convierte la imagen a escala de grises (blanco y negro).</p>';
    }

    public function render_overlay_enable_field() {
        $options = get_option('agt_settings');
        $checked = $options['agt_overlay_enable'] ?? 0;
        echo '<input type="checkbox" name="agt_settings[agt_overlay_enable]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<p class="description">Si se activa, se oscurecerá la imagen y se escribirá el título encima.</p>';
    }

    public function render_overlay_bg_color_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_overlay_bg_color'] ?? '#000000';
        echo '<input type="color" name="agt_settings[agt_overlay_bg_color]" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Este color se usará también para las imágenes de respaldo (cuando no se encuentre imagen en Google).</p>';
    }

    public function render_overlay_opacity_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_overlay_opacity'] ?? '50';
        echo '<input type="number" name="agt_settings[agt_overlay_opacity]" value="' . esc_attr($value) . '" min="0" max="100" step="1" />';
        echo '<p class="description">Porcentaje de opacidad del fondo (0 = transparente, 100 = sólido). Solo se aplica al overlay sobre imágenes de Google, no a imágenes de respaldo.</p>';
    }

    public function render_overlay_text_color_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_overlay_text_color'] ?? '#FFFFFF';
        echo '<input type="color" name="agt_settings[agt_overlay_text_color]" value="' . esc_attr($value) . '" />';
    }

    public function render_overlay_font_family_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_overlay_font_family'] ?? 'Roboto';
        
        $fonts = [ 
            'Roboto' => 'Roboto', 
            'Source' => 'Source', 
        ];
        echo '<select name="agt_settings[agt_overlay_font_family]">';
        foreach ( $fonts as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">IMPORTANTE: Debes subir el archivo .ttf (ej: <code>Roboto.ttf</code> o <code>Roboto-Bold.ttf</code>) a la carpeta <code>/fonts/</code> dentro del plugin.</p>';
    }

    public function render_overlay_font_size_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_overlay_font_size'] ?? '40';
        echo '<input type="number" name="agt_settings[agt_overlay_font_size]" value="' . esc_attr($value) . '" min="10" max="200" />';
        echo '<p class="description">Tamaño de la fuente en píxeles. Este valor será fijo para todos los títulos.</p>';
    }

    // --- NUEVAS FUNCIONES: MARCO ---

    public function render_frame_enable_field() {
        $options = get_option('agt_settings');
        $checked = $options['agt_frame_enable'] ?? 0;
        echo '<input type="checkbox" name="agt_settings[agt_frame_enable]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<p class="description">Añade un marco/borde decorativo alrededor de la imagen. Ideal para un look elegante y profesional.</p>';
    }

    public function render_frame_color_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_frame_color'] ?? '#FFFFFF';
        echo '<input type="color" name="agt_settings[agt_frame_color]" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Color del marco. Blanco es el más elegante sobre fondos oscuros.</p>';
    }

    public function render_frame_width_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_frame_width'] ?? '3';
        echo '<input type="number" name="agt_settings[agt_frame_width]" value="' . esc_attr($value) . '" min="1" max="20" step="1" />';
        echo '<p class="description">Grosor del marco en píxeles. Recomendado: 3px.</p>';
    }

    public function render_frame_margin_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_frame_margin'] ?? '40';
        echo '<input type="number" name="agt_settings[agt_frame_margin]" value="' . esc_attr($value) . '" min="10" max="200" step="5" />';
        echo '<p class="description">Separación del marco respecto a los bordes de la imagen en píxeles. Recomendado: 40px.</p>';
    }
    public function render_search_engine_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_search_engine'] ?? 'google';
        $engines = [ 
            'Google Scraping (Gratis)' => 'google', 
            'Bing API (Requiere API Key)' => 'bing'
        ];
        echo '<select name="agt_settings[agt_search_engine]">';
        foreach ( $engines as $label => $value ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Google: Scraping gratuito pero puede fallar. Bing: API fiable con 1.000 búsquedas gratis/mes.</p>';
    }

    public function render_bing_api_key_field() {
        $options = get_option('agt_settings');
        $value = $options['agt_bing_api_key'] ?? '';
        echo '<input type="text" name="agt_settings[agt_bing_api_key]" value="' . esc_attr($value) . '" class="regular-text" placeholder="Introduce tu API Key de Bing" />';
        echo '<p class="description">Obtén tu API Key gratis en: <a href="https://portal.azure.com/" target="_blank">Azure Portal</a> → Crear recurso "Bing Search v7"</p>';
    }
}

new AGT_Admin_Pages();