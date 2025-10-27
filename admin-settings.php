<?php
/**
 * La clase que maneja la página de configuración y el menú del plugin.
 */
if ( ! defined( 'WPINC' ) ) die;

class AGT_Admin_Pages {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_agt_clear_log', array( $this, 'clear_log_action' ) );
    }

    /**
     * Crea el menú principal y los submenús
     */
    public function add_admin_menu() {
        // Menú Principal
        add_menu_page(
            'Auto Google Thumbnail',    // Título de la página
            'Auto Thumbnail',           // Título del menú
            'manage_options',           // Capacidad
            'auto-google-thumbnail',    // Slug del menú principal
            array($this, 'render_settings_page'), // Función que renderiza la página por defecto (ajustes)
            'dashicons-format-image'    // Icono
        );

        // Submenú de Ajustes (re-apunta a la página principal)
        add_submenu_page(
            'auto-google-thumbnail',
            'Ajustes',
            'Ajustes',
            'manage_options',
            'auto-google-thumbnail', // Mismo slug que el padre para que sea el principal
            array($this, 'render_settings_page')
        );

        // Submenú para el Registro de Actividad
        add_submenu_page(
            'auto-google-thumbnail',
            'Registro de Actividad',
            'Registro de Actividad',
            'manage_options',
            'agt-activity-log',
            array($this, 'render_log_page')
        );
    }

    /**
     * Renderiza la página de Ajustes
     */
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

    /**
     * Renderiza la página de Registro de Actividad
     */
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
                                    $color = '#2271b1'; // Default blue
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

    /**
     * Acción para limpiar el log
     */
    public function clear_log_action() {
        if ( isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'agt_clear_log_nonce') && current_user_can('manage_options') ) {
            delete_option('agt_activity_log');
        }
        wp_redirect(admin_url('admin.php?page=agt-activity-log'));
        exit;
    }

    /**
     * Registra todos los ajustes, secciones y campos del formulario
     */
    public function register_settings() {
        register_setting( 'agt_settings_group', 'agt_settings' );

        add_settings_section( 'agt_general_section', 'Ajustes Generales', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_enable_field', 'Activar Plugin', array( $this, 'render_enable_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_selection_field', 'Selección de Imagen', array( $this, 'render_selection_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        add_settings_field( 'agt_language_field', 'Idioma de Búsqueda', array( $this, 'render_language_field' ), 'auto-google-thumbnail-settings', 'agt_general_section' );
        
        add_settings_section( 'agt_filter_section', 'Filtros de Búsqueda', null, 'auto-google-thumbnail-settings' );
        add_settings_field( 'agt_rights_field', 'Derechos de Uso', array( $this, 'render_rights_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_filetype_field', 'Tipo de Archivo (Extensión)', array( $this, 'render_filetype_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_format_field', 'Formato de Imagen', array( $this, 'render_format_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_size_field', 'Tamaño Mínimo', array( $this, 'render_size_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
        add_settings_field( 'agt_type_field', 'Tipo de Imagen', array( $this, 'render_type_field' ), 'auto-google-thumbnail-settings', 'agt_filter_section' );
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
        $selections = [ 'Primera imagen encontrada' => 'first', 'Imagen aleatoria' => 'random' ];
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
}

new AGT_Admin_Pages();