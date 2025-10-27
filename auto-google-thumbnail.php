<?php
/**
 * Plugin Name:       Auto Google Thumbnail
 * Plugin URI:        https://albertomurillo.pro/auto-google-thumbnail
 * Description:       Establece automáticamente una imagen destacada desde Google Imágenes basándose en el título de la entrada.
 * Version:           1.0.1
 * Author:            Alberto Murillo
 * Author URI:        https://albertomurillo.pro/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       auto-google-thumbnail
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once plugin_dir_path( __FILE__ ) . 'admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'bulk-generate.php';

/**
 * Añade tipos MIME adicionales para permitir más extensiones de imagen.
 */
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

    /**
     * Filtro para desactivar sslverify únicamente en peticiones a Google Imágenes
     * o descargas directas de imágenes específicas.
     */
    public function filter_request_args( $args, $url ) {
        $extensiones_imagen = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'svg' );
        $path = parse_url( $url, PHP_URL_PATH );
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        $is_google_search   = strpos( $url, 'google.com/search?tbm=isch' ) !== false;
        $is_image_download  = in_array( $ext, $extensiones_imagen );

        if ( $is_google_search || $is_image_download ) {
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
     * Hook en 'save_post'. Lanza la generación automática de la imagen destacada.
     */
   public function on_save_post( $post_id, $post ) {
    // Evita revisiones y autoguardados
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }

    // Salir si NO es 'post'
    if ( get_post_type( $post_id ) !== 'post' ) {
        return;
    }

    $this->set_featured_image_from_google( $post_id );
}

    /**
     * Manejador AJAX para generar la miniatura de un post específico.
     */
    public function handle_ajax_generation() {
        check_ajax_referer( 'agt_bulk_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'No tienes permisos.', 'auto-google-thumbnail' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( __( 'ID de post no válido.', 'auto-google-thumbnail' ) );
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
        // 1. COMPROBACIONES INICIALES
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return false;
        }

        $post_status = get_post_status( $post_id );
        $allowed     = array( 'publish', 'future', 'private' );
        if ( ! in_array( $post_status, $allowed, true ) ) {
            return false;
        }

        $this->log_message( sprintf(
            __( 'Iniciando proceso para Post ID: %d (Estado: %s).', 'auto-google-thumbnail' ),
            $post_id,
            $post_status
        ) );

        if ( has_post_thumbnail( $post_id ) ) {
            $this->log_message( __( 'Proceso abortado: El post ya tiene una imagen destacada.', 'auto-google-thumbnail' ), 'INFO' );
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
        );
        $options  = wp_parse_args( get_option( 'agt_settings', array() ), $defaults );

        if ( empty( $options['agt_enable'] ) ) {
            $this->log_message( __( 'Proceso abortado: El plugin está desactivado en los ajustes.', 'auto-google-thumbnail' ), 'INFO' );
            return false;
        }

        $search_term = get_the_title( $post_id );
        if ( empty( $search_term ) ) {
            $this->log_message( __( 'Proceso abortado: El post no tiene título.', 'auto-google-thumbnail' ), 'ERROR' );
            return false;
        }

        // Añadimos filetype al término si está configurado
        $filetype_filter = $options['agt_filetype'];
        if ( 'all' !== $filetype_filter ) {
            $search_term .= ' filetype:' . $filetype_filter;
        }

        $this->log_message( sprintf(
            __( 'Término de búsqueda obtenido: \'%s\'.', 'auto-google-thumbnail' ),
            $search_term
        ) );

        // Construcción de parámetros tbs
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

        // 2. CONSTRUCCIÓN DE LA URL DE BÚSQUEDA
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

        // 3. PETICIÓN Y SCRAPING
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

        // Filtrar por extensión si corresponde
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

        // Seleccionar orden de candidatos: primera o aleatoria
        if ( 'random' === $options['agt_selection'] && count( $candidates ) > 1 ) {
            $random_key = array_rand( $candidates );
            $ordered = array( $candidates[ $random_key ] );
            // El resto en orden original, por si el primero falla
            foreach ( $candidates as $i => $u ) {
                if ( $i !== $random_key ) {
                    $ordered[] = $u;
                }
            }
        } else {
            $ordered = $candidates;
        }

        // 4. DESCARGA Y ASIGNACIÓN ITERATIVA
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ( $ordered as $url ) {
            $url = trim( $url );

            // 4.1 Si es un URL de Next.js (_next/image?url=...), extraer URL real
            if ( preg_match( '/_next\/image\?url=([^&]+)/', $url, $m ) ) {
                $decoded_url = urldecode( $m[1] );
                if ( filter_var( $decoded_url, FILTER_VALIDATE_URL ) ) {
                    $url = $decoded_url;
                    $this->log_message( sprintf(
                        __( 'Extraída URL real de Next.js: %s', 'auto-google-thumbnail' ),
                        $url
                    ), 'INFO' );
                }
            }

            // 4.2 Descartar dominios que requieran autenticación (ej. lookaside.fbsbx.com)
            if ( strpos( $url, 'lookaside.fbsbx.com' ) !== false ) {
                $this->log_message( sprintf(
                    __( 'Descartada URL de Facebook: %s', 'auto-google-thumbnail' ),
                    $url
                ), 'INFO' );
                continue;
            }

            // 4.3 HEAD para comprobar estado y Content-Type
            $head = wp_remote_head( $url, $args );
            if ( is_wp_error( $head ) ) {
                $this->log_message( sprintf(
                    __( 'HEAD falló para %s: %s', 'auto-google-thumbnail' ),
                    $url,
                    $head->get_error_message()
                ), 'ERROR' );
                continue;
            }
            $status = wp_remote_retrieve_response_code( $head );
            if ( 200 !== intval( $status ) ) {
                $this->log_message( sprintf(
                    __( 'HEAD devolvió código %d para %s', 'auto-google-thumbnail' ),
                    $status,
                    $url
                ), 'ERROR' );
                continue;
            }

            $content_type = strtolower( wp_remote_retrieve_header( $head, 'content-type' ) );
            if ( false === strpos( $content_type, 'image/' ) ) {
                $this->log_message( sprintf(
                    __( 'Content-Type no es imagen para %s: %s', 'auto-google-thumbnail' ),
                    $url,
                    $content_type
                ), 'ERROR' );
                continue;
            }

            // 4.4 Descarga a archivo temporal y forzar extensión si hace falta
            $tmp_file = download_url( $url );
            if ( is_wp_error( $tmp_file ) ) {
                $this->log_message( sprintf(
                    __( 'download_url falló para %s: %s', 'auto-google-thumbnail' ),
                    $url,
                    $tmp_file->get_error_message()
                ), 'ERROR' );
                continue;
            }

            // Determinar extensión a partir de Content-Type o URL
            $path     = parse_url( $url, PHP_URL_PATH );
            $ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ), true ) ) {
                // Si no es una extensión reconocida, asignar según content-type
                if ( strpos( $content_type, 'jpeg' ) !== false ) {
                    $ext = 'jpg';
                } elseif ( strpos( $content_type, 'png' ) !== false ) {
                    $ext = 'png';
                } elseif ( strpos( $content_type, 'gif' ) !== false ) {
                    $ext = 'gif';
                } elseif ( strpos( $content_type, 'webp' ) !== false ) {
                    $ext = 'webp';
                } else {
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

            // 4.5 Asignar como destacado y actualizar ALT
            set_post_thumbnail( $post_id, $attach_id );
            update_post_meta( $attach_id, '_wp_attachment_image_alt', $search_term );

            $this->log_message( sprintf(
                __( '¡ÉXITO! Imagen generada y asignada al post (ID de adjunto: %d).', 'auto-google-thumbnail' ),
                $attach_id
            ), 'SUCCESS' );

            return true;
        }

        // Si llegamos aquí, ninguna URL funcionó
        $this->log_message( __( 'Ninguna URL candidata pudo descargarse correctamente.', 'auto-google-thumbnail' ), 'ERROR' );
        return false;
    }
}

new Auto_Google_Thumbnail();