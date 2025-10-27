<?php
/**
 * Añade la página de administración para la generación en lote.
 */
if ( ! defined( 'WPINC' ) ) die;

class AGT_Bulk_Generate_Page {

    public function __construct() {
        // Enganchamos la creación de la página al menú de administración.
        add_action('admin_menu', array($this, 'add_admin_page'));
    }

    /**
     * Añade la página como un submenú de nuestro menú principal.
     */
    public function add_admin_page() {
        add_submenu_page(
            'auto-google-thumbnail',      // Slug de la página padre (nuestro menú principal)
            'Generación en Lote',         // Título de la página
            'Generación en Lote',         // Título del menú
            'manage_options',             // Capacidad requerida para verla
            'agt-bulk-generate',          // Slug de esta página
            array($this, 'render_page')   // Función que "dibuja" la página
        );
    }

    /**
     * Dibuja el contenido HTML y JavaScript de la página.
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Generación de Imágenes Destacadas en Lote</h1>
            <p>Esta herramienta buscará todos los artículos publicados que no tengan una imagen destacada y te permitirá generarlas masivamente.</p>
            <p><strong>Nota:</strong> El proceso puede tardar varios minutos dependiendo del número de artículos. Por favor, no cierres esta pestaña hasta que finalice.</p>

            <div id="bulk-controls" style="margin-top: 20px;">
                <button id="start-bulk-generate" class="button button-primary button-hero">Iniciar Generación</button>
                <button id="select-all" class="button button-secondary">Seleccionar/Deseleccionar Todo</button>
            </div>

            <div id="progress-container" style="margin-top: 20px; display: none;">
                <h3>Progreso:</h3>
                <div class="progress-bar" style="width: 100%; box-sizing: border-box; background-color: #ddd; border: 1px solid #ccc; padding: 2px;">
                    <div id="progress-bar-inner" style="width: 0%; height: 30px; background-color: #007cba; text-align: center; line-height: 30px; color: white; transition: width 0.5s ease;">0%</div>
                </div>
                <div id="progress-log" style="margin-top: 10px; font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: scroll; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;"></div>
            </div>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="select-all-header" /></th>
                        <th>Título del Artículo</th>
                        <th style="width: 250px;">Estado</th>
                    </tr>
                </thead>
                <tbody id="bulk-list">
                    <?php
                    // Preparamos la consulta para obtener los posts sin imagen destacada
                    $args = array(
                        'post_type'      => 'post',
                        'post_status'    => 'publish',
                        'posts_per_page' => -1, // -1 para obtener todos
                        'meta_query'     => array(
                            array(
                                'key'     => '_thumbnail_id',
                                'compare' => 'NOT EXISTS' // La condición mágica: que no exista la meta key de la thumbnail.
                            )
                        )
                    );
                    $posts_query = new WP_Query($args);

                    if ($posts_query->have_posts()) :
                        while ($posts_query->have_posts()) : $posts_query->the_post();
                            ?>
                            <tr data-id="<?php echo get_the_ID(); ?>">
                                <th class="check-column"><input type="checkbox" name="post_ids[]" value="<?php echo get_the_ID(); ?>"></th>
                                <td><a href="<?php echo get_edit_post_link(); ?>" target="_blank"><?php the_title(); ?></a></td>
                                <td class="status"><em>Pendiente</em></td>
                            </tr>
                            <?php
                        endwhile;
                        wp_reset_postdata();
                    else :
                        ?>
                        <tr><td colspan="3">¡Felicidades! Todos tus artículos publicados tienen una imagen destacada.</td></tr>
                        <?php
                    endif;
                    ?>
                </tbody>
            </table>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Lógica para el botón de "Seleccionar Todos"
                $('#select-all, #select-all-header').on('click', function(e) {
                    e.preventDefault();
                    var isChecked = $('#bulk-list input[type="checkbox"]:first').prop('checked');
                    $('#bulk-list input[type="checkbox"]').prop('checked', !isChecked);
                });

                // Lógica principal al pulsar el botón de "Iniciar Generación"
                $('#start-bulk-generate').on('click', function() {
                    var postIds = $('input[name="post_ids[]"]:checked').map(function() {
                        return $(this).val();
                    }).get();

                    if (postIds.length === 0) {
                        alert('Por favor, selecciona al menos un artículo para procesar.');
                        return;
                    }

                    // Bloqueamos los botones y mostramos el progreso
                    $(this).prop('disabled', true);
                    $('#select-all').prop('disabled', true);
                    $('#progress-container').show();
                    $('#progress-log').html('');

                    var totalPosts = postIds.length;
                    var processedCount = 0;

                    // Función recursiva que procesa un post y luego se llama a sí misma para el siguiente
                    function processNext(index) {
                        // Si ya hemos procesado todos, terminamos.
                        if (index >= totalPosts) {
                            $('#progress-log').prepend('<div><strong>¡Proceso completado!</strong></div>');
                            $('#start-bulk-generate').prop('disabled', false);
                            $('#select-all').prop('disabled', false);
                            return;
                        }

                        var postId = postIds[index];
                        var $row = $('tr[data-id="' + postId + '"]');
                        var $status = $row.find('.status');
                        
                        $status.html('<strong>Procesando...</strong>');
                        $('#progress-log').prepend('<div>ID ' + postId + ': Iniciando...</div>');

                        // La llamada AJAX
                        $.ajax({
                            url: ajaxurl, // URL ajax de WordPress (variable global)
                            type: 'POST',
                            data: {
                                action: 'agt_generate_single', // La acción que definimos en el PHP principal
                                post_id: postId,
                                nonce: '<?php echo wp_create_nonce("agt_bulk_nonce"); ?>' // Nonce de seguridad
                            },
                            success: function(response) {
                                if (response.success) {
                                    $status.html('<strong style="color: green;">Éxito</strong> <img src="' + response.data.thumbnail_url + '" width="40" height="40" style="vertical-align: middle; margin-left: 10px;" />');
                                    $('#progress-log').prepend('<div>ID ' + postId + ': Éxito.</div>');
                                } else {
                                    $status.html('<strong style="color: red;">Error:</strong> ' + response.data);
                                    $('#progress-log').prepend('<div>ID ' + postId + ': Error - ' + response.data + '</div>');
                                }
                            },
                            error: function() {
                                $status.html('<strong style="color: red;">Error de Conexión</strong>');
                                $('#progress-log').prepend('<div>ID ' + postId + ': Error de conexión con el servidor.</div>');
                            },
                            complete: function() {
                                processedCount++;
                                var percent = (processedCount / totalPosts) * 100;
                                $('#progress-bar-inner').css('width', percent + '%').text(Math.round(percent) + '%');
                                processNext(index + 1); // ¡Llamada al siguiente!
                            }
                        });
                    }

                    // Empezamos con el primer artículo de la lista (índice 0)
                    processNext(0);
                });
            });
        </script>
        <?php
    }
}

new AGT_Bulk_Generate_Page();