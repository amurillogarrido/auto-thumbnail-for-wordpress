<?php
/**
 * Plugin Name:       Auto Thumbnail for WordPress
 * Plugin URI:        https://github.com/amurillogarrido/auto-thumbnail-for-wordpress
 * Description:       Genera imágenes destacadas automáticamente desde Google y crea portadas profesionales con texto.
 * Version:           1.0.6
 * Author:            Alberto Murillo
 * Text Domain:       auto-google-thumbnail
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
        // Desactivamos verificación SSL para Google Imágenes y para descargar fuentes de GitHub
        $args['sslverify'] = false;
        return $args;
    }

    private function log_message( $message, $type = 'INFO' ) {
        $log = get_option( 'agt_activity_log', array() );
        array_unshift( $log, array(
            'date'    => current_time( 'mysql' ),
            'message' => $message,
            'type'    => $type,
        ) );
        if ( count( $log ) > 100 ) $log = array_slice( $log, 0, 100 );
        update_option( 'agt_activity_log', $log );
    }

    public function on_save_post( $post_id, $post ) {
        if ( get_post_type( $post_id ) !== 'post' ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        $this->set_featured_image_from_google( $post_id );
    }

    public function handle_ajax_generation() {
        check_ajax_referer( 'agt_bulk_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Sin permisos.' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || get_post_type($post_id) !== 'post' ) wp_send_json_error( 'ID inválido.' );

        $result = $this->set_featured_image_from_google( $post_id );

        if ( $result ) {
            wp_send_json_success( array( 'thumbnail_url' => get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ) );
        } else {
            wp_send_json_error( 'Fallo al generar. Ver log.' );
        }
    }

    public function set_featured_image_from_google( $post_id ) {
        if ( has_post_thumbnail( $post_id ) ) return false;

        $options = get_option( 'agt_settings', array() );
        if ( empty( $options['agt_enable'] ) ) return false;

        $search_term = get_the_title( $post_id );
        $this->log_message( "Buscando imagen para: $search_term" );

        // Configurar Query
        $q_param = $search_term;
        if ( !empty($options['agt_filetype']) && $options['agt_filetype'] !== 'all' ) {
            $q_param .= ' filetype:' . $options['agt_filetype'];
        }

        $query_args = array(
            'q'   => $q_param,
            'tbm' => 'isch',
            'hl'  => $options['agt_language'] ?? 'es',
        );

        // Filtros TBS
        $tbs = [];
        if(!empty($options['agt_rights'])) $tbs[] = 'sur:'.$options['agt_rights'];
        if(!empty($options['agt_size'])) $tbs[] = 'isz:'.$options['agt_size'];
        if(!empty($options['agt_format'])) $tbs[] = 'iar:'.$options['agt_format'];
        if(!empty($tbs)) $query_args['tbs'] = implode(',', $tbs);

        $url = add_query_arg( $query_args, 'https://www.google.com/search' );
        
        // User Agent móvil moderno
        $args = array(
            'user-agent' => 'Mozilla/5.0 (Linux; Android 10; SM-G960F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.101 Mobile Safari/537.36',
            'timeout'    => 15,
            'sslverify'  => false
        );

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            $this->log_message( "Error Google: " . $response->get_error_message(), 'ERROR' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        
        // Extracción de imágenes
        preg_match_all( '/data-ou="(http[^"]+)"/', $body, $matches );
        if(empty($matches[1])) {
            preg_match_all( '/https?:\/\/[^"]+\.(jpg|jpeg|png|webp)/i', $body, $matches_secondary );
            $candidates = !empty($matches_secondary[0]) ? $matches_secondary[0] : [];
        } else {
            $candidates = $matches[1];
        }

        if ( empty( $candidates ) ) {
            $this->log_message( "No se encontraron imágenes.", 'ERROR' );
            return false;
        }

        if ( ($options['agt_selection'] ?? 'first') === 'random' ) shuffle($candidates);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ( $candidates as $img_url ) {
            $img_url = urldecode($img_url);
            
            // Descarga temporal
            $tmp_file = download_url( $img_url );
            if ( is_wp_error( $tmp_file ) ) continue;

            // --- PROCESADO DE PORTADA (Lógica Nueva) ---
            // Solo procesamos si la opción 'agt_overlay_enable' está marcada en admin-settings.php
            if ( !empty($options['agt_overlay_enable']) ) {
                $this->process_image_overlay( $tmp_file, $search_term, $options );
            }
            // -------------------------------------------

            $file_type = wp_check_filetype( $img_url );
            $ext = $file_type['ext'] ? $file_type['ext'] : 'jpg';
            
            $file_array = array(
                'name'     => sanitize_file_name( $search_term ) . '.' . $ext,
                'tmp_name' => $tmp_file,
            );

            $attach_id = media_handle_sideload( $file_array, $post_id, $search_term );

            if ( is_wp_error( $attach_id ) ) {
                @unlink( $tmp_file );
                $this->log_message( "Error guardando: " . $attach_id->get_error_message(), 'ERROR' );
                continue;
            }

            set_post_thumbnail( $post_id, $attach_id );
            $this->log_message( "Imagen asignada (ID: $attach_id)", 'SUCCESS' );
            return true;
        }

        $this->log_message( "No se pudo procesar ninguna imagen.", 'ERROR' );
        return false;
    }

    /**
     * Aplica capa oscura y texto TTF sobre la imagen usando GD.
     */
    private function process_image_overlay( $file_path, $text, $options ) {
        $info = @getimagesize( $file_path );
        if ( !$info ) return;

        $width  = $info[0];
        $height = $info[1];
        $mime   = $info['mime'];

        switch ( $mime ) {
            case 'image/jpeg': $im = @imagecreatefromjpeg( $file_path ); break;
            case 'image/png':  $im = @imagecreatefrompng( $file_path ); break;
            case 'image/gif':  $im = @imagecreatefromgif( $file_path ); break;
            case 'image/webp': $im = @imagecreatefromwebp( $file_path ); break;
            default: return;
        }
        if ( !$im ) return;

        // 1. Capa Oscura (Opacidad configurable)
        $opacity_pct = isset($options['agt_overlay_opacity']) ? intval($options['agt_overlay_opacity']) : 50;
        if ( $opacity_pct > 0 ) {
            $overlay = imagecreatetruecolor( $width, $height );
            $black   = imagecolorallocate( $overlay, 0, 0, 0 );
            imagefill( $overlay, 0, 0, $black );
            imagecopymerge( $im, $overlay, 0, 0, 0, 0, $width, $height, $opacity_pct );
            imagedestroy( $overlay );
        }

        // 2. Texto con fuente TTF
        $font_name = isset($options['agt_font_family']) ? $options['agt_font_family'] : 'Roboto';
        $font_file = $this->get_font_path( $font_name );

        if ( $font_file && file_exists( $font_file ) ) {
            // Tamaño
            $size_px = isset($options['agt_font_size']) ? intval($options['agt_font_size']) : 50;
            $size_pt = $size_px * 0.75; // GD usa puntos
            
            // Color
            $hex = isset($options['agt_font_color']) ? $options['agt_font_color'] : '#ffffff';
            $rgb = $this->hex2rgb($hex);
            $color = imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );

            // Wrapping y Márgenes
            $margin = 40;
            $max_width = $width - ($margin * 2);
            
            $words = explode(' ', $text);
            $lines = [];
            $current_line = '';

            // Calcular líneas
            foreach ($words as $word) {
                $test_line = $current_line . ($current_line ? ' ' : '') . $word;
                $bbox = imagettfbbox($size_pt, 0, $font_file, $test_line);
                $text_width = $bbox[2] - $bbox[0];
                
                if ($text_width > $max_width && !empty($current_line)) {
                    $lines[] = $current_line;
                    $current_line = $word;
                } else {
                    $current_line = $test_line;
                }
            }
            $lines[] = $current_line;

            // Calcular posición vertical
            $line_height = $size_px * 1.4;
            $text_block_height = count($lines) * $line_height;
            $start_y = ($height - $text_block_height) / 2 + $size_px;

            // Dibujar texto
            foreach ($lines as $i => $line ) {
                $bbox = imagettfbbox($size_pt, 0, $font_file, $line);
                $text_width = $bbox[2] - $bbox[0];
                $start_x = ($width - $text_width) / 2; // Centrado horizontal
                
                imagettftext($im, $size_pt, 0, $start_x, $start_y + ($i * $line_height), $color, $font_file, $line);
            }
        } else {
            // Fallback por si falla la descarga de la fuente (mensaje de error en log)
            $this->log_message("Error: Fuente no disponible ($font_name).", 'ERROR');
        }

        // Guardar la imagen procesada
        switch ( $mime ) {
            case 'image/jpeg': imagejpeg( $im, $file_path, 90 ); break;
            case 'image/png':  imagepng( $im, $file_path ); break;
            case 'image/webp': imagewebp( $im, $file_path, 90 ); break;
        }
        imagedestroy( $im );
    }

    /**
     * Descarga y cachea la fuente desde Google Fonts (GitHub Mirror).
     */
    private function get_font_path( $font_name ) {
        $upload_dir = wp_upload_dir();
        $fonts_dir  = $upload_dir['basedir'] . '/agt-fonts';
        if ( ! file_exists( $fonts_dir ) ) wp_mkdir_p( $fonts_dir );

        // URLs directas a las versiones Bold de las fuentes en GitHub
        $fonts_map = [
            'Roboto'       => 'https://github.com/google/fonts/raw/main/apache/roboto/Roboto-Bold.ttf',
            'Open Sans'    => 'https://github.com/google/fonts/raw/main/ofl/opensans/OpenSans-Bold.ttf',
            'Montserrat'   => 'https://github.com/google/fonts/raw/main/ofl/montserrat/Montserrat-Bold.ttf',
            'Lato'         => 'https://github.com/google/fonts/raw/main/ofl/lato/Lato-Bold.ttf',
            'Oswald'       => 'https://github.com/google/fonts/raw/main/ofl/oswald/Oswald-Bold.ttf',
            'Merriweather' => 'https://github.com/google/fonts/raw/main/ofl/merriweather/Merriweather-Bold.ttf',
            'Anton'        => 'https://github.com/google/fonts/raw/main/ofl/anton/Anton-Regular.ttf'
        ];

        if ( ! isset( $fonts_map[ $font_name ] ) ) $font_name = 'Roboto';
        $url = $fonts_map[ $font_name ];
        $filename = sanitize_file_name( $font_name ) . '-bold.ttf';
        $file_path = $fonts_dir . '/' . $filename;

        // Si ya existe, usamos la caché
        if ( file_exists( $file_path ) ) return $file_path;

        // Si no, descargar
        $response = wp_remote_get( $url );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            file_put_contents( $file_path, wp_remote_retrieve_body( $response ) );
            return $file_path;
        }
        return false;
    }

    private function hex2rgb( $hex ) {
        $hex = str_replace( '#', '', $hex );
        if ( strlen( $hex ) === 3 ) {
            $r = hexdec( substr( $hex, 0, 1 ) . substr( $hex, 0, 1 ) );
            $g = hexdec( substr( $hex, 1, 1 ) . substr( $hex, 1, 1 ) );
            $b = hexdec( substr( $hex, 2, 1 ) . substr( $hex, 2, 1 ) );
        } else {
            $r = hexdec( substr( $hex, 0, 2 ) );
            $g = hexdec( substr( $hex, 2, 2 ) );
            $b = hexdec( substr( $hex, 4, 2 ) );
        }
        return array( $r, $g, $b );
    }
}

new Auto_Google_Thumbnail();