<?php
if ( ! defined( 'WPINC' ) ) die;

class AGT_Admin_Pages {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_agt_clear_log', array( $this, 'clear_log_action' ) );
        // Enganchamos la carga de scripts para el selector de color
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * Carga los estilos y scripts necesarios (Color Picker de WP)
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        // Solo cargar en las páginas de nuestro plugin
        if ( strpos( $hook_suffix, 'auto-google-thumbnail' ) === false ) {
            return;
        }
        
        // Cargar librerías nativas de WordPress para el selector de color
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'agt-admin-script', false, array( 'wp-color-picker' ), false, true );
        
        // Script inline para inicializar el color picker
        wp_add_inline_script( 'agt-admin-script', 'jQuery(document).ready(function($){ $(".agt-color-field").wpColorPicker(); });' );
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
            <p>Aquí puedes ver qué está haciendo el plugin internamente.</p>
            
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
        // Registramos el grupo de opciones con una función de sanitización
        register_setting( 'agt_settings_group', 'agt_settings', array( $this, 'sanitize_settings' ) );

        // --- SECCIÓN 1: GENERAL ---
        add_settings_section( 'agt_general_section', 'Ajustes Generales', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_enable_field', 'Activar Plugin', array( $this, 'render_enable_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        
        // --- SECCIÓN 2: DISEÑO DE PORTADA (NUEVO) ---
        add_settings_section( 'agt_design_section', 'Diseño de Portada Automática', array($this, 'design_section_info'), 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_overlay_enable_field', 'Activar Portada', array( $this, 'render_overlay_enable_field' ), 'auto-google-thumbnail-settings', 'agt_design_section' );
        add_settings_field( 'agt_font_family_field', 'Tipografía (Google Fonts)', array( $this, 'render_font_family_field' ), 'auto-google-thumbnail-settings', 'agt_design_section' );
        add_settings_field( 'agt_font_size_field', 'Tamaño del Texto (px)', array( $this, 'render_font_size_field' ), 'auto-google-thumbnail-settings', 'agt_design_section' );
        add_settings_field( 'agt_font_color_field', 'Color del Texto', array( $this, 'render_font_color_field' ), 'auto-google-thumbnail-settings', 'agt_design_section' );
        add_settings_field( 'agt_overlay_opacity_field', 'Opacidad del Fondo Oscuro', array( $this, 'render_overlay_opacity_field' ), 'auto-google-thumbnail-settings', 'agt_design_section' );

        // --- SECCIÓN 3: FILTROS DE BÚSQUEDA ---
        add_settings_section( 'agt_filter_section', 'Filtros de Búsqueda en Google', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_selection_field', 'Selección de Imagen', array( $this, 'render_selection_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_language_field', 'Idioma de Búsqueda', array( $this, 'render_language_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_rights_field', 'Derechos de Uso', array( $this, 'render_rights_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_filetype_field', 'Tipo de Archivo', array( $this, 'render_filetype_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_size_field', 'Tamaño Mínimo', array( $this, 'render_size_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_format_field', 'Orientación', array( $this, 'render_format_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
    }

    /**
     * Limpia y valida los datos antes de guardarlos en la base de datos
     */
    public function sanitize_settings( $input ) {
        $input['agt_enable'] = isset( $input['agt_enable'] ) ? 1 : 0;
        $input['agt_overlay_enable'] = isset( $input['agt_overlay_enable'] ) ? 1 : 0;
        
        // Validar tamaño de fuente (entre 10 y 150 px)
        $input['agt_font_size'] = absint( $input['agt_font_size'] );
        if ( $input['agt_font_size'] < 10 ) $input['agt_font_size'] = 10;
        if ( $input['agt_font_size'] > 150 ) $input['agt_font_size'] = 150;

        // Validar opacidad (0 a 100)
        $input['agt_overlay_opacity'] = absint( $input['agt_overlay_opacity'] );
        if ( $input['agt_overlay_opacity'] > 100 ) $input['agt_overlay_opacity'] = 100;

        // Validar color hexadecimal
        $input['agt_font_color'] = sanitize_hex_color( $input['agt_font_color'] );
        
        return $input;
    }

    public function design_section_info() {
        echo '<p>Personaliza la apariencia. La fuente elegida se descargará automáticamente a tu servidor la primera vez que se use.</p>';
    }

    public function render_enable_field() {
        $options = get_option('agt_settings');
        echo '<input type="checkbox" name="agt_settings[agt_enable]" value="1" ' . checked( 1, $options['agt_enable'] ?? 0, false ) . ' />';
        echo '<p class="description">Marcar para activar la búsqueda automática.</p>';
    }

    public function render_overlay_enable_field() {
        $options = get_option('agt_settings');
        echo '<input type="checkbox" name="agt_settings[agt_overlay_enable]" value="1" ' . checked( 1, $options['agt_overlay_enable'] ?? 0, false ) . ' />';
        echo '<p class="description">Si se activa, se aplicará una capa oscura y se escribirá el título sobre la imagen.</p>';
    }

    public function render_font_family_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_font_family'] ?? 'Roboto';
        // Lista de fuentes seguras de Google Fonts
        $fonts = [
            'Roboto' => 'Roboto (Moderna y Estándar)',
            'Open Sans' => 'Open Sans (Limpia y Legible)',
            'Montserrat' => 'Montserrat (Geométrica y Elegante)',
            'Lato' => 'Lato (Equilibrada)',
            'Oswald' => 'Oswald (Estilo Titular/Poster)',
            'Merriweather' => 'Merriweather (Clásica con Serifa)',
            'Anton' => 'Anton (Impactante y Gruesa)'
        ];
        echo '<select name="agt_settings[agt_font_family]">';
        foreach ( $fonts as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_font_size_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_font_size'] ?? 50;
        echo '<input type="number" name="agt_settings[agt_font_size]" value="' . esc_attr( $val ) . '" min="10" max="150" step="1" />';
        echo '<p class="description">Tamaño en píxeles. Recomendado entre 40 y 80.</p>';
    }

    public function render_font_color_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_font_color'] ?? '#ffffff';
        // Clase 'agt-color-field' activa el selector de color de WP
        echo '<input type="text" name="agt_settings[agt_font_color]" value="' . esc_attr( $val ) . '" class="agt-color-field" />';
    }

    public function render_overlay_opacity_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_overlay_opacity'] ?? 50;
        echo '<div style="display: flex; align-items: center; gap: 10px;">';
        echo '<input type="range" name="agt_settings[agt_overlay_opacity]" value="' . esc_attr( $val ) . '" min="0" max="100" step="5" oninput="this.nextElementSibling.value = this.value + \'%\'" />';
        echo '<output style="font-weight: bold;">' . esc_attr( $val ) . '%</output>';
        echo '</div>';
        echo '<p class="description">0% = Transparente, 100% = Totalmente negro.</p>';
    }

    // --- RENDERIZADO DE FILTROS (Sin cambios importantes) ---
    public function render_selection_field() {
        $options = get_option('agt_settings');
        $selected = $options['agt_selection'] ?? 'first';
        echo '<select name="agt_settings[agt_selection]"><option value="first" '.selected($selected,'first',false).'>Primera imagen</option><option value="random" '.selected($selected,'random',false).'>Aleatoria</option></select>';
    }
    public function render_language_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_language'] ?? 'es';
        $langs = ['es'=>'Español','en'=>'Inglés','fr'=>'Francés','de'=>'Alemán','it'=>'Italiano','pt'=>'Portugués'];
        echo '<select name="agt_settings[agt_language]">';
        foreach($langs as $k=>$v) echo '<option value="'.$k.'" '.selected($val,$k,false).'>'.$v.'</option>';
        echo '</select>';
    }
    public function render_rights_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_rights'] ?? 'fmc';
        $rights = ['fmc'=>'Reutilización con mod.','fc'=>'Reutilización',''=>'Cualquiera (Riesgo)'];
        echo '<select name="agt_settings[agt_rights]">';
        foreach($rights as $k=>$v) echo '<option value="'.$k.'" '.selected($val,$k,false).'>'.$v.'</option>';
        echo '</select>';
    }
    public function render_filetype_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_filetype'] ?? 'all';
        $types = ['all'=>'Cualquiera','jpg'=>'JPG','png'=>'PNG','webp'=>'WEBP'];
        echo '<select name="agt_settings[agt_filetype]">';
        foreach($types as $k=>$v) echo '<option value="'.$k.'" '.selected($val,$k,false).'>'.$v.'</option>';
        echo '</select>';
    }
    public function render_size_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_size'] ?? 'l';
        $sizes = [''=>'Cualquiera','l'=>'Grande','m'=>'Mediana','lt,islt:xga'=>'Mayor que XGA'];
        echo '<select name="agt_settings[agt_size]">';
        foreach($sizes as $k=>$v) echo '<option value="'.$k.'" '.selected($val,$k,false).'>'.$v.'</option>';
        echo '</select>';
    }
    public function render_format_field() {
        $options = get_option('agt_settings');
        $val = $options['agt_format'] ?? '';
        $formats = [''=>'Cualquiera','w'=>'Horizontal','t'=>'Vertical','s'=>'Cuadrada'];
        echo '<select name="agt_settings[agt_format]">';
        foreach($formats as $k=>$v) echo '<option value="'.$k.'" '.selected($val,$k,false).'>'.$v.'</option>';
        echo '</select>';
    }
}
new AGT_Admin_Pages();