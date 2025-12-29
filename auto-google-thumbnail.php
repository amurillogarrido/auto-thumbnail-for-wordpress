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
            // Valores por defecto overlay y filtros
            'agt_grayscale_enable' => 0, // Nuevo: Filtro B&N
            'agt_overlay_enable'   => 0,
            'agt_overlay_bg_color' => '#000000',
            'agt_overlay_opacity'  => '50',
            'agt_overlay_text_color' => '#FFFFFF',
            'agt_overlay_font_family' => 'Roboto',
            'agt_overlay_font_size' => '40',
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

        $filetype_filter = $options['agt_filetype'];
        if ( 'all' !== $filetype_filter ) {
            $search_term .= ' filetype:' . $filetype_filter;
        }

        $this->log_message( sprintf(
            __( 'Término de búsqueda obtenido: \'%s\'.', 'auto-google-thumbnail' ),
            $search_term
        ) );

        $tbs_parts = array();
        if ( ! empty( $options['agt_rights'] ) ) {
            $tbs_parts[] = 'sur:' . $options['agt_rights'];
        }
        if ( ! empty( $options['agt_size'] ) ) {
            $tbs_parts[] = 'isz:' . $options['agt_size'];
        }
        if ( ! empty( $options['agt_format'] ) ) {
            $tbs_parts[] = 'iar:' . $options['agt_format'];
        }
        if ( ! empty( $options['agt_type'] ) ) {
            $tbs_parts[] = 'itp:' . $options['agt_type'];
        }

        $query_args = array(
            'q'   => $search_term,
            'tbm' => 'isch',
            'hl'  => $options['agt_language'],
        );
        if ( ! empty( $tbs_parts ) ) {
            $query_args['tbs'] = implode( ',', $tbs_parts );
        }

        $search_url = add_query_arg( $query_args, 'https://www.google.com/search' );
        $this->log_message( sprintf(
            __( 'URL de Google construida: %s', 'auto-google-thumbnail' ),
            $search_url
        ) );

        // --- USER AGENT ---
        $args = array(
            'user-agent' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96',
            'sslverify'  => false,
            'timeout'    => 15,
        );

        $this->log_message( __( 'Enviando petición a Google...', 'auto-google-thumbnail' ) );
        $response = wp_remote_get( $search_url, $args );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $code = is_wp_error( $response )
                ? $response->get_error_message()
                : wp_remote_retrieve_response_code( $response );
            $this->log_message( sprintf(
                __( 'Error en la petición a Google. Código/razón: %s', 'auto-google-thumbnail' ),
                $code
            ), 'ERROR' );
            return false;
        }

        $html_body = wp_remote_retrieve_body( $response );
        preg_match_all( '/data-ou="(http[^"]*)"/', $html_body, $matches );
        if ( empty( $matches[1] ) ) {
            $this->log_message( __( 'No se encontraron imágenes en la respuesta de Google.', 'auto-google-thumbnail' ), 'ERROR' );
            return false;
        }

        $candidates = $matches[1];
        $this->log_message( sprintf(
            __( 'Se encontraron %d imágenes candidatas iniciales.', 'auto-google-thumbnail' ),
            count( $candidates )
        ) );

        if ( 'all' !== $filetype_filter ) {
            $filtered = array();
            foreach ( $candidates as $url ) {
                $ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
                if ( $ext === $filetype_filter ) {
                    $filtered[] = $url;
                }
            }
            $candidates = $filtered;
            $this->log_message( sprintf(
                __( "Tras filtrar por '.%s', quedan %d imágenes.", 'auto-google-thumbnail' ),
                $filetype_filter,
                count( $candidates )
            ) );
            if ( empty( $candidates ) ) {
                $this->log_message( __( 'Ninguna imagen candidata superó el filtro de tipo de archivo.', 'auto-google-thumbnail' ), 'ERROR' );
                return false;
            }
        }

        if ( 'random' === $options['agt_selection'] && count( $candidates ) > 1 ) {
            $random_key = array_rand( $candidates );
            $ordered = array( $candidates[ $random_key ] );
            foreach ( $candidates as $i => $u ) {
                if ( $i !== $random_key ) {
                    $ordered[] = $u;
                }
            }
        } else {
            $ordered = $candidates;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ( $ordered as $url ) {
            $url = trim( $url );

            if ( preg_match( '/_next\/image\?url=([^&]+)/', $url, $m ) ) {
                $decoded_url = urldecode( $m[1] );
                if ( filter_var( $decoded_url, FILTER_VALIDATE_URL ) ) {
                    $url = $decoded_url;
                }
            }

            if ( strpos( $url, 'lookaside.fbsbx.com' ) !== false ) {
                continue;
            }

            // Descargar la imagen a temporal
            $tmp_file = download_url( $url );
            if ( is_wp_error( $tmp_file ) ) {
                $this->log_message( sprintf(
                    __( 'download_url falló para %s: %s', 'auto-google-thumbnail' ),
                    $url,
                    $tmp_file->get_error_message()
                ), 'ERROR' );
                continue;
            }

            // --- PROCESAMIENTO DE IMAGEN (Overlay / Filtros) ---
            // Procesamos si hay overlay O si hay filtro escala de grises
            if ( !empty($options['agt_overlay_enable']) || !empty($options['agt_grayscale_enable']) ) {
                $titulo_entrada = get_the_title( $post_id );
                $processed = $this->process_image_overlay( $tmp_file, $titulo_entrada, $options );
                
                if ( ! $processed ) {
                    $this->log_message( __( 'Error aplicando edición de imagen. Usando imagen original.', 'auto-google-thumbnail' ), 'INFO' );
                } else {
                    $this->log_message( __( 'Edición de imagen aplicada correctamente.', 'auto-google-thumbnail' ), 'SUCCESS' );
                }
            }
            // --------------------------------------

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
        return false;
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