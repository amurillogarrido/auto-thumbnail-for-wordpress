<?php
/**
 * Plugin Name:       Auto Thumbnail for WordPress
 * Plugin URI:        https://github.com/amurillogarrido/auto-thumbnail-for-wordpress
 * Description:       Establece automáticamente una imagen destacada desde Google Imágenes basándose en el título de la entrada.
 * Version:           1.0.9
 * Author:            Alberto Murillo
 * Author URI:        https://albertomurillo.pro/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       auto-thumbnail-for-wordpress
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once plugin_dir_path( __FILE__ ) . 'admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'bulk-generate.php';

add_filter( 'upload_mimes', function( $mimes ) {
    $mimes['bmp']  = 'image/bmp';
    $mimes['webp'] = 'image/webp';
    $mimes['svg']  = 'image/svg+xml';
    return $mimes;
}, 10, 1 );

class Auto_Google_Thumbnail {

    public function __construct() {
        add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
        add_action( 'wp_ajax_agt_generate_single', array( $this, 'handle_ajax_generation' ) );
        add_filter( 'http_request_args', array( $this, 'filter_request_args' ), 10, 2 );
    }

    public function filter_request_args( $args, $url ) {
        $extensiones_imagen = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'svg' );
        $path = parse_url( $url, PHP_URL_PATH );
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        $is_google_search   = strpos( $url, 'google.com/search?tbm=isch' ) !== false;
        $is_image_download  = in_array( $ext, $extensiones_imagen );
        
        // Mantenemos esto por compatibilidad, aunque ya no descargamos fuentes externamente
        $is_font_download   = strpos( $url, 'github.com/google/fonts' ) !== false || strpos( $url, 'raw.githubusercontent.com' ) !== false;

        if ( $is_google_search || $is_image_download || $is_font_download ) {
            $args['sslverify'] = false;
        }

        return $args;
    }

    /**
     * Registra un mensaje en el log de actividad (opción 'agt_activity_log').
     *
     * @param string $message El mensaje a registrar.
     * @param string $type    Tipo de mensaje: 'INFO', 'SUCCESS' o 'ERROR'.
     */
    private function log_message( $message, $type = 'INFO' ) {
        $log = get_option( 'agt_activity_log', array() );

        array_unshift( $log, array(
            'date'    => current_time( 'mysql' ),
            'message' => $message,
            'type'    => $type,
        ) );

        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, 0, 100 );
        }

        update_option( 'agt_activity_log', $log );
    }

   /**
    * Se ejecuta cuando se guarda un post. Decide si generar la imagen destacada.
    *
    * @param int     $post_id ID del post guardado.
    * @param WP_Post $post    Objeto del post guardado.
    */
   public function on_save_post( $post_id, $post ) {
        // Si no es una entrada ('post'), no hacemos nada.
        if ( get_post_type( $post_id ) !== 'post' ) {
            return; 
        }

        // Comprobaciones existentes (revisiones, auto-guardados)
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // El resto de la lógica solo se ejecutará si es un 'post'
        $this->set_featured_image_from_google( $post_id );
    }


    public function handle_ajax_generation() {
        check_ajax_referer( 'agt_bulk_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'No tienes permisos.', 'auto-google-thumbnail' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( __( 'ID de post no válido.', 'auto-google-thumbnail' ) );
        }
        
        // Asegurarnos de que el AJAX solo procese posts
        if ( get_post_type( $post_id ) !== 'post' ) {
             wp_send_json_error( __( 'Este ID no corresponde a una entrada.', 'auto-google-thumbnail' ) );
        }

        $result = $this->set_featured_image_from_google( $post_id );

        if ( $result ) {
            $thumb_url = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
            wp_send_json_success( array( 'thumbnail_url' => $thumb_url ) );
        } else {
            wp_send_json_error( __( 'No se pudo generar la imagen. Revisa el registro de actividad.', 'auto-google-thumbnail' ) );
        }
    }

    /**
     * Intenta asignar automáticamente una imagen destacada al post indicado.
     *
     * @param int $post_id ID del post.
     * @return bool True si se asignó con éxito, false en caso contrario.
     */
    public function set_featured_image_from_google( $post_id ) {
        // Comprobaciones iniciales (revisiones, autosaves)
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return false;
        }
        
        // Volvemos a asegurar que solo procesamos 'post'
        if ( get_post_type( $post_id ) !== 'post' ) {
            $this->log_message( sprintf(
                __( 'Proceso abortado: El ID %d no es una entrada (es %s).', 'auto-google-thumbnail' ),
                $post_id,
                get_post_type( $post_id )
            ), 'INFO' );
            return false;
        }

        $post_status = get_post_status( $post_id );
        $allowed     = array( 'publish', 'future', 'private' );
        if ( ! in_array( $post_status, $allowed, true ) ) {
             $this->log_message( sprintf(
                __( 'Proceso abortado para Post ID: %d. Estado no permitido (%s).', 'auto-google-thumbnail' ),
                $post_id,
                $post_status
            ), 'INFO' );
            return false; // No procesar borradores, pendientes, etc.
        }

        $this->log_message( sprintf(
            __( 'Iniciando proceso para Entrada ID: %d (Estado: %s).', 'auto-google-thumbnail' ),
            $post_id,
            $post_status
        ) );

        if ( has_post_thumbnail( $post_id ) ) {
            $this->log_message( __( 'Proceso abortado: La entrada ya tiene una imagen destacada.', 'auto-google-thumbnail' ), 'INFO' );
            return false;
        }

        $defaults = array(
            'agt_enable'    => 0,
            'agt_filetype'  => 'all',
            'agt_rights'    => '',
            'agt_size'      => '',
            'agt_format'    => '',
            'agt_type'      => '',
            'agt_language'  => 'es',
            'agt_selection' => 'first',
            'agt_blacklist' => '', // Nuevo campo blacklist
            // Valores por defecto overlay y filtros
            'agt_grayscale_enable' => 0, // Nuevo: Filtro B&N
            'agt_overlay_enable'   => 0,
            'agt_overlay_bg_color' => '#000000',
            'agt_overlay_opacity'  => '50',
            'agt_overlay_text_color' => '#FFFFFF',
            'agt_overlay_font_family' => 'Roboto',
            'agt_overlay_font_size' => '40',
            'agt_fallback_enable'  => 1, // NUEVO: Activar imagen de respaldo por defecto
        );
        $options  = wp_parse_args( get_option( 'agt_settings', array() ), $defaults );

        if ( empty( $options['agt_enable'] ) ) {
            $this->log_message( __( 'Proceso abortado: El plugin está desactivado en los ajustes.', 'auto-google-thumbnail' ), 'INFO' );
            return false;
        }

        $search_term = get_the_title( $post_id );
        if ( empty( $search_term ) ) {
            $this->log_message( __( 'Proceso abortado: La entrada no tiene título.', 'auto-google-thumbnail' ), 'ERROR' );
            return false;
        }

        $this->log_message( sprintf( __( 'Término de búsqueda: "%s"', 'auto-google-thumbnail' ), $search_term ) );

        $query_params = array(
            'q'    => urlencode( $search_term ),
            'tbm'  => 'isch',
            'hl'   => $options['agt_language'],
        );

        if ( ! empty( $options['agt_rights'] ) ) {
            $query_params['tbs'] = 'il:cl,sur:' . $options['agt_rights'];
        }
        if ( ! empty( $options['agt_size'] ) ) {
            $query_params['tbs'] = ( isset( $query_params['tbs'] ) ? $query_params['tbs'] . ',' : '' ) . 'isz:' . $options['agt_size'];
        }
        if ( ! empty( $options['agt_format'] ) ) {
            $query_params['tbs'] = ( isset( $query_params['tbs'] ) ? $query_params['tbs'] . ',' : '' ) . 'iar:' . $options['agt_format'];
        }
        if ( ! empty( $options['agt_type'] ) ) {
            $query_params['tbs'] = ( isset( $query_params['tbs'] ) ? $query_params['tbs'] . ',' : '' ) . 'itp:' . $options['agt_type'];
        }
        
        // CAMBIO: Validación específica de agt_filetype
        $valid_filetypes = ['jpg', 'png', 'webp', 'all'];
        if (!empty($options['agt_filetype']) && in_array($options['agt_filetype'], $valid_filetypes) && $options['agt_filetype'] !== 'all') {
            $query_params['tbs'] = (isset($query_params['tbs']) ? $query_params['tbs'] . ',' : '') . 'ift:' . $options['agt_filetype'];
        }

        $search_url = 'https://www.google.com/search?' . http_build_query( $query_params );

        $this->log_message( sprintf( __( 'URL de búsqueda: %s', 'auto-google-thumbnail' ), $search_url ) );

        $response = wp_remote_get( $search_url, array(
            'timeout'     => 30,
            'httpversion' => '1.1',
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_message( sprintf( __( 'Error al obtener resultados de Google: %s', 'auto-google-thumbnail' ), $response->get_error_message() ), 'ERROR' );
            // NUEVO: Intentar generar imagen de respaldo
            return $this->generate_fallback_image( $post_id, $search_term, $options );
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            $this->log_message( __( 'El cuerpo de la respuesta de Google está vacío.', 'auto-google-thumbnail' ), 'ERROR' );
            // NUEVO: Intentar generar imagen de respaldo
            return $this->generate_fallback_image( $post_id, $search_term, $options );
        }

        // CAMBIO: Procesamos la Blacklist desde los ajustes
        $blacklist_raw = isset($options['agt_blacklist']) ? $options['agt_blacklist'] : '';
        $blacklist_domains = array();
        
        if (!empty($blacklist_raw)) {
            // Dividir por comas y/o saltos de línea
            $items = preg_split('/[\s,]+/', $blacklist_raw, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($items as $item) {
                $item = trim($item);
                // Limpiar protocolo y barras para obtener solo el dominio
                $item = preg_replace('#^https?://#i', '', $item);
                $item = rtrim($item, '/');
                if (!empty($item)) {
                    $blacklist_domains[] = strtolower($item);
                }
            }
        }

        preg_match_all( '/\["(https?:\/\/[^"]+\.(?:jpg|jpeg|png|gif|webp|bmp))"\s*,\s*(\d+)\s*,\s*(\d+)\]/i', $html, $matches, PREG_SET_ORDER );

        if ( empty( $matches ) ) {
            $this->log_message( __( 'No se encontraron URLs de imágenes válidas en la respuesta de Google.', 'auto-google-thumbnail' ), 'ERROR' );
            // NUEVO: Intentar generar imagen de respaldo
            return $this->generate_fallback_image( $post_id, $search_term, $options );
        }

        $this->log_message( sprintf( __( 'Se encontraron %d imágenes candidatas.', 'auto-google-thumbnail' ), count( $matches ) ) );

        $candidates = array();
        foreach ( $matches as $match ) {
            $url    = $match[1];
            $width  = intval( $match[2] );
            $height = intval( $match[3] );
            
            // CAMBIO: Aplicar Blacklist
            $domain = parse_url($url, PHP_URL_HOST);
            if ($domain) {
                $domain = strtolower($domain);
                $is_blocked = false;
                foreach ($blacklist_domains as $blocked) {
                    // Comparar si el dominio contiene el patrón bloqueado
                    if (strpos($domain, $blocked) !== false) {
                        $is_blocked = true;
                        break;
                    }
                }
                if ($is_blocked) {
                    $this->log_message( sprintf( __( 'Imagen excluida por blacklist: %s (dominio: %s)', 'auto-google-thumbnail' ), $url, $domain ), 'INFO' );
                    continue; // Saltar esta imagen
                }
            }
            
            if ( $width < 200 || $height < 200 ) {
                continue;
            }
            $score = $width * $height;
            $candidates[] = array( 'url' => $url, 'score' => $score );
        }

        if ( empty( $candidates ) ) {
            $this->log_message( __( 'No se encontraron imágenes candidatas válidas después de aplicar filtros y blacklist.', 'auto-google-thumbnail' ), 'ERROR' );
            // NUEVO: Intentar generar imagen de respaldo
            return $this->generate_fallback_image( $post_id, $search_term, $options );
        }

        if ( $options['agt_selection'] === 'best' ) {
            usort( $candidates, function( $a, $b ) {
                return $b['score'] - $a['score'];
            } );
        }

        $url_to_try = $candidates[0]['url'];

        require_once ABSPATH . 'wp-admin/includes/file.php';

        foreach ( $candidates as $candidate ) {
            $url = $candidate['url'];
            $this->log_message( sprintf( __( 'Intentando descargar: %s', 'auto-google-thumbnail' ), $url ) );

            $tmp_file = download_url( $url, 60 );
            if ( is_wp_error( $tmp_file ) ) {
                $this->log_message( sprintf(
                    __( 'download_url() falló para %s: %s', 'auto-google-thumbnail' ),
                    $url,
                    $tmp_file->get_error_message()
                ), 'ERROR' );
                continue;
            }

            // --- APLICAR PROCESADO DE IMAGEN (Server-Side) ---
            // Si se activa overlay o filtros, procesamos antes de importar
            if ( !empty($options['agt_overlay_enable']) || !empty($options['agt_grayscale_enable']) ) {
                $result = $this->process_image_overlay( $tmp_file, $search_term, $options );
                if ( !$result ) {
                    $this->log_message( sprintf(
                        __( 'No se pudo procesar la imagen (overlay/filtros) para %s', 'auto-google-thumbnail' ),
                        $url
                    ), 'ERROR' );
                    @unlink( $tmp_file );
                    continue; // Intentar siguiente candidato
                }
            }

            // Obtener extensión real (después del procesado, siempre es JPG si se procesó)
            $path     = parse_url( $url, PHP_URL_PATH );
            $ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            
            // Si procesamos la imagen (overlay o filtros), convertimos a JPG internamente
            if ( !empty($options['agt_overlay_enable']) || !empty($options['agt_grayscale_enable']) ) {
                $ext = 'jpg';
            } else {
                 // Validar extensión original si no usamos edición
                 if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ), true ) ) {
                    $ext = 'jpg'; 
                 }
            }

            $file_array = array(
                'name'     => sanitize_file_name( $search_term ) . '.' . $ext,
                'tmp_name' => $tmp_file,
            );

            $attach_id = media_handle_sideload( $file_array, $post_id, $search_term );
            if ( is_wp_error( $attach_id ) ) {
                @unlink( $tmp_file );
                $this->log_message( sprintf(
                    __( 'media_handle_sideload falló para %s: %s', 'auto-google-thumbnail' ),
                    $url,
                    $attach_id->get_error_message()
                ), 'ERROR' );
                continue;
            }

            set_post_thumbnail( $post_id, $attach_id );
            update_post_meta( $attach_id, '_wp_attachment_image_alt', $search_term );

            $this->log_message( sprintf(
                __( '¡ÉXITO! Imagen generada y asignada a la entrada (ID de adjunto: %d).', 'auto-google-thumbnail' ),
                $attach_id
            ), 'SUCCESS' );

            return true;
        }

        $this->log_message( __( 'Ninguna URL candidata pudo descargarse correctamente.', 'auto-google-thumbnail' ), 'ERROR' );
        // NUEVO: Intentar generar imagen de respaldo como último recurso
        return $this->generate_fallback_image( $post_id, $search_term, $options );
    }

    /**
     * NUEVA FUNCIÓN: Genera una imagen de respaldo cuando no se encuentra ninguna en Google
     * Crea una imagen desde cero con el color de fondo y el título
     * 
     * @param int $post_id ID del post
     * @param string $text Título del post
     * @param array $options Opciones del plugin
     * @return bool True si se generó con éxito, false en caso contrario
     */
    private function generate_fallback_image( $post_id, $text, $options ) {
        // Verificar si la función de respaldo está habilitada
        if ( empty( $options['agt_fallback_enable'] ) ) {
            $this->log_message( __( 'Imagen de respaldo desactivada en los ajustes. No se generará imagen.', 'auto-google-thumbnail' ), 'INFO' );
            return false;
        }

        $this->log_message( __( 'Iniciando generación de imagen de respaldo (sin imagen de Google).', 'auto-google-thumbnail' ), 'INFO' );

        // Dimensiones de la imagen (1200x630 es un buen tamaño para imágenes destacadas)
        $width = 1200;
        $height = 630;

        // Crear imagen en blanco
        $im = imagecreatetruecolor( $width, $height );
        if ( ! $im ) {
            $this->log_message( __( 'Error al crear la imagen de respaldo.', 'auto-google-thumbnail' ), 'ERROR' );
            return false;
        }

        // 1. Aplicar color de fondo
        $hex = $options['agt_overlay_bg_color'] ?? '#000000';
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }

        $bg_color = imagecolorallocate( $im, $r, $g, $b );
        imagefilledrectangle( $im, 0, 0, $width, $height, $bg_color );

        // 2. Aplicar filtro de escala de grises si está activado
        if ( !empty($options['agt_grayscale_enable']) ) {
            imagefilter($im, IMG_FILTER_GRAYSCALE);
        }

        // 3. Configurar texto
        $font_name = $options['agt_overlay_font_family'] ?? 'Roboto';
        $font_file = $this->get_font_file( $font_name );
        $font_size = intval( $options['agt_overlay_font_size'] ?? 40 );

        $text_hex = $options['agt_overlay_text_color'] ?? '#FFFFFF';
        $text_hex = ltrim($text_hex, '#');
        if (strlen($text_hex) == 3) {
            $tr = hexdec(substr($text_hex, 0, 1) . substr($text_hex, 0, 1));
            $tg = hexdec(substr($text_hex, 1, 1) . substr($text_hex, 1, 1));
            $tb = hexdec(substr($text_hex, 2, 1) . substr($text_hex, 2, 1));
        } else {
            $tr = hexdec(substr($text_hex, 0, 2));
            $tg = hexdec(substr($text_hex, 2, 2));
            $tb = hexdec(substr($text_hex, 4, 2));
        }
        $text_color = imagecolorallocate( $im, $tr, $tg, $tb );

        // 4. Word Wrap (Ajuste de líneas)
        if ( ! $font_file || ! file_exists( $font_file ) ) {
            imagestring($im, 5, 10, 10, "Error: Sube la fuente " . $font_name . ".ttf a /fonts/", $text_color);
        } else {
            $words = explode( ' ', $text );
            $lines = array();
            $current_line = '';
            $max_width = $width * 0.8;

            foreach ( $words as $word ) {
                $test_line = $current_line . ($current_line ? ' ' : '') . $word;
                $bbox = imagettfbbox( $font_size, 0, $font_file, $test_line );
                $line_width = abs( $bbox[4] - $bbox[0] );

                if ( $line_width > $max_width && !empty($current_line) ) {
                    $lines[] = $current_line;
                    $current_line = $word;
                } else {
                    $current_line = $test_line;
                }
            }
            $lines[] = $current_line;

            // 5. Centrar y dibujar texto
            $line_height = $font_size * 1.5;
            $total_text_height = count($lines) * $line_height;
            $y_start = ( $height - $total_text_height ) / 2 + $font_size;

            foreach ( $lines as $i => $line ) {
                $bbox = imagettfbbox( $font_size, 0, $font_file, $line );
                $text_w = abs( $bbox[4] - $bbox[0] );
                $x_pos = ( $width - $text_w ) / 2;
                $y_pos = $y_start + ( $i * $line_height );

                imagettftext( $im, $font_size, 0, $x_pos, $y_pos, $text_color, $font_file, $line );
            }
        }

        // 6. Guardar en archivo temporal
        $upload_dir = wp_upload_dir();
        $tmp_file = $upload_dir['path'] . '/fallback-' . sanitize_file_name( $text ) . '-' . time() . '.jpg';
        
        $saved = imagejpeg( $im, $tmp_file, 90 );
        imagedestroy( $im );

        if ( ! $saved || ! file_exists( $tmp_file ) ) {
            $this->log_message( __( 'Error al guardar la imagen de respaldo en el servidor.', 'auto-google-thumbnail' ), 'ERROR' );
            return false;
        }

        // 7. Importar a WordPress
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file_array = array(
            'name'     => sanitize_file_name( $text ) . '.jpg',
            'tmp_name' => $tmp_file,
        );

        $attach_id = media_handle_sideload( $file_array, $post_id, $text );
        
        if ( is_wp_error( $attach_id ) ) {
            @unlink( $tmp_file );
            $this->log_message( sprintf(
                __( 'Error al importar imagen de respaldo: %s', 'auto-google-thumbnail' ),
                $attach_id->get_error_message()
            ), 'ERROR' );
            return false;
        }

        set_post_thumbnail( $post_id, $attach_id );
        update_post_meta( $attach_id, '_wp_attachment_image_alt', $text );

        $this->log_message( sprintf(
            __( '¡ÉXITO! Imagen de respaldo generada y asignada a la entrada (ID de adjunto: %d).', 'auto-google-thumbnail' ),
            $attach_id
        ), 'SUCCESS' );

        return true;
    }

    /**
     * Aplica Edición Server-Side: Filtros (B&N) + Overlay oscuro + Texto
     * * @param string $file_path Ruta local del archivo temporal de imagen.
     * @param string $text      Texto a escribir (título).
     * @param array  $options   Opciones del plugin.
     * @return bool True si éxito, False si falló.
     */
    private function process_image_overlay( $file_path, $text, $options ) {
        $info = getimagesize( $file_path );
        if ( ! $info ) return false;

        $mime = $info['mime'];
        $width = $info[0];
        $height = $info[1];

        // Crear recurso de imagen según tipo
        switch ( $mime ) {
            case 'image/jpeg': $im = imagecreatefromjpeg( $file_path ); break;
            case 'image/png':  $im = imagecreatefrompng( $file_path ); break;
            case 'image/webp': $im = imagecreatefromwebp( $file_path ); break;
            default: return false; // Formato no soportado por GD fácilmente o bmp/gif animado
        }
        if ( ! $im ) return false;

        // --- APLICAR FILTRO BLANCO Y NEGRO ---
        if ( !empty($options['agt_grayscale_enable']) ) {
            imagefilter($im, IMG_FILTER_GRAYSCALE);
        }

        // --- APLICAR OVERLAY DE TEXTO (Si está activado) ---
        if ( !empty($options['agt_overlay_enable']) ) {
            
            // 1. Aplicar Overlay (Capa oscura)
            $hex = $options['agt_overlay_bg_color'] ?? '#000000';
            $opacity = intval( $options['agt_overlay_opacity'] ?? 50 ); // 0 a 100
            
            // Convertir Hex a RGB
            $hex = ltrim($hex, '#');
            if (strlen($hex) == 3) {
                $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
                $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
                $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
            } else {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
            }

            // GD Alpha: 0 (opaco) a 127 (transparente). Convertimos input 0-100 a 0-127 invertido.
            $alpha = (int) ( ( 100 - $opacity ) * 1.27 );
            
            $overlay_color = imagecolorallocatealpha( $im, $r, $g, $b, $alpha );
            imagefilledrectangle( $im, 0, 0, $width, $height, $overlay_color );

            // 2. Configurar Texto y Fuente
            $font_name = $options['agt_overlay_font_family'] ?? 'Roboto';
            
            // MODIFICADO: Busca la fuente localmente en la carpeta 'fonts'
            $font_file = $this->get_font_file( $font_name ); 

            $font_size = intval( $options['agt_overlay_font_size'] ?? 40 );
            
            $text_hex = $options['agt_overlay_text_color'] ?? '#FFFFFF';
            $text_hex = ltrim($text_hex, '#');
            if (strlen($text_hex) == 3) {
                $tr = hexdec(substr($text_hex, 0, 1) . substr($text_hex, 0, 1));
                $tg = hexdec(substr($text_hex, 1, 1) . substr($text_hex, 1, 1));
                $tb = hexdec(substr($text_hex, 2, 1) . substr($text_hex, 2, 1));
            } else {
                $tr = hexdec(substr($text_hex, 0, 2));
                $tg = hexdec(substr($text_hex, 2, 2));
                $tb = hexdec(substr($text_hex, 4, 2));
            }
            $text_color = imagecolorallocate( $im, $tr, $tg, $tb );

            // 3. Word Wrap (Ajuste de líneas)
            $words = explode( ' ', $text );
            $lines = array();
            $current_line = '';
            $max_width = $width * 0.8; // Margen 10% a cada lado

            // Si la fuente no se pudo cargar, usar fuente del sistema
            if ( ! $font_file || ! file_exists( $font_file ) ) {
                // Fallback básico
                 imagestring($im, 5, 10, 10, "Error: Sube la fuente " . $font_name . ".ttf a /fonts/", $text_color);
            } else {
                foreach ( $words as $word ) {
                    $test_line = $current_line . ($current_line ? ' ' : '') . $word;
                    $bbox = imagettfbbox( $font_size, 0, $font_file, $test_line );
                    $line_width = abs( $bbox[4] - $bbox[0] );

                    if ( $line_width > $max_width && !empty($current_line) ) {
                        $lines[] = $current_line;
                        $current_line = $word;
                    } else {
                        $current_line = $test_line;
                    }
                }
                $lines[] = $current_line;

                // 4. Centrar y Dibujar Texto
                $line_height = $font_size * 1.5;
                $total_text_height = count($lines) * $line_height;
                $y_start = ( $height - $total_text_height ) / 2 + $font_size; // Ajuste por baseline

                foreach ( $lines as $i => $line ) {
                    $bbox = imagettfbbox( $font_size, 0, $font_file, $line );
                    $text_w = abs( $bbox[4] - $bbox[0] );
                    $x_pos = ( $width - $text_w ) / 2;
                    $y_pos = $y_start + ( $i * $line_height );

                    imagettftext( $im, $font_size, 0, $x_pos, $y_pos, $text_color, $font_file, $line );
                }
            }
        } // Fin if overlay_enable

        // 5. Guardar sobre el archivo temporal
        // Convertimos todo a JPG para estandarizar output
        imagejpeg( $im, $file_path, 90 );
        imagedestroy( $im );

        return true;
    }

    /**
     * MODIFICADO: Busca la fuente localmente en la carpeta /fonts/ del plugin.
     * No descarga nada. El usuario debe subir la fuente.
     */
    private function get_font_file( $font_name ) {
        // Carpeta 'fonts' dentro del plugin
        $font_dir = plugin_dir_path( __FILE__ ) . 'fonts/';
        
        // Lista de posibles nombres de archivo que el usuario podría haber subido
        // Probamos con y sin "-Bold", en minúsculas y tal cual viene del select.
        $possible_names = [
            $font_name . '-Bold.ttf',
            $font_name . '.ttf',
            strtolower($font_name) . '-bold.ttf',
            strtolower($font_name) . '.ttf'
        ];

        foreach ($possible_names as $file) {
            if ( file_exists( $font_dir . $file ) ) {
                return $font_dir . $file;
            }
        }

        return false;
    }
}

new Auto_Google_Thumbnail();