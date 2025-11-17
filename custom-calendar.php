<?php
/*
Plugin Name: Custom Calendar
Description: Календарь с анимациями и событиями
Version: 2.0
Author: RainbowInPants
*/

// Активация плагина: создание таблицы для событий
register_activation_hook(__FILE__, 'custom_calendar_activate');
function custom_calendar_activate() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Таблица событий
    $table_name = $wpdb->prefix . 'custom_events';
    $sql = "CREATE TABLE $table_name (
        id INT(9) NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        start_date DATETIME NOT NULL,
        end_date DATETIME,
        description TEXT,
        color VARCHAR(20),
        recurrence ENUM('none','daily','weekly','monthly') DEFAULT 'none',
        recurrence_end DATE,
        category_id INT(9),
        post_id BIGINT(20) UNSIGNED NULL,
        PRIMARY KEY (id)
    ) " . $wpdb->get_charset_collate() . ";";
    dbDelta($sql);

    // Гарантируем наличие колонки post_id для привязки к записям/постам
    $columns = $wpdb->get_col("DESC $table_name", 0);
    if (!in_array('post_id', $columns, true)) {
        $wpdb->query("ALTER TABLE $table_name ADD post_id BIGINT(20) UNSIGNED NULL AFTER category_id");
    }

    // Таблица категорий
    $categories_table = $wpdb->prefix . 'custom_event_categories';
    $sql = "CREATE TABLE $categories_table (
        id INT(9) NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        color VARCHAR(20) NOT NULL,
        PRIMARY KEY (id)
    ) " . $wpdb->get_charset_collate() . ";";
    dbDelta($sql);

    // Добавляем тестовые категории, если таблица пуста
    if ($wpdb->get_var("SELECT COUNT(*) FROM $categories_table") == 0) {
        $wpdb->insert($categories_table, ['name' => 'Встречи', 'color' => '#3788d8']);
        $wpdb->insert($categories_table, ['name' => 'Праздники', 'color' => '#d83737']);
    }
}

// Добавляем шорткод [custom_calendar] с параметрами
add_shortcode('custom_calendar', 'render_custom_calendar');
function render_custom_calendar($atts = []) {
    $atts = shortcode_atts([
        'view' => 'full', // 'full' или 'mini'
        'height' => 'auto'
    ], $atts);
    
    ob_start();
    ?>
    <div class="custom-calendar-container" 
         data-view="<?php echo esc_attr($atts['view']); ?>"
         data-height="<?php echo esc_attr($atts['height']); ?>">
        <div id="custom-calendar"></div>

        <!-- Popup события календаря -->
        <div class="custom-calendar-modal" id="custom-calendar-modal" aria-hidden="true">
            <div class="custom-calendar-modal__overlay" data-calendar-modal-close></div>
            <div class="custom-calendar-modal__content" role="dialog" aria-modal="true">
                <button type="button" class="custom-calendar-modal__close" data-calendar-modal-close>&times;</button>
                <h3 class="custom-calendar-modal__title"></h3>
                <div class="custom-calendar-modal__meta"></div>
                <div class="custom-calendar-modal__description"></div>
                <a href="#" target="_blank" rel="noopener" class="custom-calendar-modal__link"></a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Подключение скриптов и стилей
add_action('wp_enqueue_scripts', 'enqueue_calendar_scripts');
function enqueue_calendar_scripts() {
    // FullCalendar (CDN)
    wp_enqueue_style('fullcalendar-css', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css');
    wp_enqueue_script('fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js', ['jquery'], null, true);
    
    // Русская локализация
    wp_enqueue_script('fullcalendar-locale', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/locales/ru.min.js', ['fullcalendar-js'], null, true);
    
    // Наши файлы
    wp_enqueue_script('custom-calendar-js', plugin_dir_url(__FILE__) . 'js/custom-calendar.js', ['jquery', 'fullcalendar-js', 'fullcalendar-locale'], '1.1', true);
    wp_enqueue_style('custom-calendar-css', plugin_dir_url(__FILE__) . 'css/custom-calendar.css');
    
    // Локализация AJAX
    wp_localize_script('custom-calendar-js', 'calendar_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('calendar_nonce'),
        'locale' => 'ru'
    ]);
}

// AJAX: Загрузка событий
add_action('wp_ajax_get_events', 'get_calendar_events');
add_action('wp_ajax_nopriv_get_events', 'get_calendar_events');
function get_calendar_events() {
    check_ajax_referer('calendar_nonce', 'nonce');
    
    global $wpdb;
    $table = $wpdb->prefix . 'custom_events';
    $events = $wpdb->get_results("SELECT * FROM $table");
    
    $response = [];
    foreach ($events as $event) {
        // Определяем ссылку на связанный пост, если он существует
        $event_url = (!empty($event->post_id) && get_post_status($event->post_id))
            ? get_permalink($event->post_id)
            : null;

        // Для не повторяющихся событий
        if ($event->recurrence == 'none') {
            $response[] = [
                'id' => $event->id,
                'title' => $event->title,
                'start' => $event->start_date,
                'end' => $event->end_date,
                'color' => $event->color,
                'description' => $event->description,
                'url' => $event_url
            ];
        } else {
            // Генерация повторяющихся событий
            $start = new DateTime($event->start_date);
            $end = $event->recurrence_end ? new DateTime($event->recurrence_end) : new DateTime('+1 year');
            
            while ($start <= $end) {
                $response[] = [
                    'id' => $event->id,
                    'title' => $event->title,
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $event->end_date 
                        ? $start->format('Y-m-d') . substr($event->end_date, 10)
                        : null,
                    'color' => $event->color,
                    'description' => $event->description,
                    'extendedProps' => ['recurring' => true],
                    'url' => $event_url
                ];
                
                // Добавляем интервал в зависимости от типа повтора
                switch ($event->recurrence) {
                    case 'daily':
                        $start->modify('+1 day');
                        break;
                    case 'weekly':
                        $start->modify('+1 week');
                        break;
                    case 'monthly':
                        $start->modify('+1 month');
                        break;
                }
            }
        }
    }
    
    wp_send_json($response);
}

// Админ-меню для добавления событий
add_action('admin_menu', 'calendar_admin_menu');
function calendar_admin_menu() {
    add_menu_page(
        'Custom Calendar',
        'Calendar Events',
        'manage_options',
        'calendar-events',
        'render_admin_page',
        'dashicons-calendar-alt'
    );
}

// AJAX: Удаление события
add_action('wp_ajax_delete_event', 'delete_calendar_event');
function delete_calendar_event() {
    check_ajax_referer('calendar_nonce', 'nonce');
    
    global $wpdb;
    $table = $wpdb->prefix . 'custom_events';
    $wpdb->delete($table, ['id' => intval($_POST['event_id'])]);
    wp_send_json_success();
}

// AJAX: Получение данных события для редактирования
add_action('wp_ajax_get_event', 'get_calendar_event');
function get_calendar_event() {
    check_ajax_referer('calendar_nonce', 'nonce');
    
    global $wpdb;
    $table = $wpdb->prefix . 'custom_events';
    $event = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id = %d",
        intval($_POST['event_id'])
    ));
    
    wp_send_json_success($event);
}

// AJAX: Обновление события
add_action('wp_ajax_update_event', 'update_calendar_event');
function update_calendar_event() {
    check_ajax_referer('calendar_nonce', 'nonce');
    
    global $wpdb;
    $table = $wpdb->prefix . 'custom_events';
    
    $data = [
        'title' => sanitize_text_field($_POST['title']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'description' => sanitize_textarea_field($_POST['description']),
        'color' => sanitize_hex_color($_POST['color']),
        'recurrence' => in_array($_POST['recurrence'], ['none','daily','weekly','monthly']) 
            ? $_POST['recurrence'] 
            : 'none',
        'recurrence_end' => !empty($_POST['recurrence_end']) 
            ? sanitize_text_field($_POST['recurrence_end']) 
            : null,
        'category_id' => !empty($_POST['category_id']) 
            ? intval($_POST['category_id']) 
            : null,
        'post_id' => !empty($_POST['post_id']) 
            ? intval($_POST['post_id']) 
            : null
    ];
    
    $wpdb->update($table, $data, ['id' => intval($_POST['event_id'])]);
    wp_send_json_success();
}

// Обновленная функция render_admin_page
function render_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'custom_events';
    $categories_table = $wpdb->prefix . 'custom_event_categories';
    
    // Обработка добавления события
    if (isset($_POST['add_event']) && check_admin_referer('event_action', 'event_nonce')) {
        $data = [
            'title' => sanitize_text_field($_POST['title']),
            'start_date' => sanitize_text_field($_POST['start_date']),
            'end_date' => sanitize_text_field($_POST['end_date']),
            'description' => sanitize_textarea_field($_POST['description']),
            'color' => sanitize_hex_color($_POST['color']),
            'recurrence' => in_array($_POST['recurrence'], ['none','daily','weekly','monthly']) 
                ? $_POST['recurrence'] 
                : 'none',
            'recurrence_end' => !empty($_POST['recurrence_end']) 
                ? sanitize_text_field($_POST['recurrence_end']) 
                : null,
            'category_id' => !empty($_POST['category_id']) 
                ? intval($_POST['category_id']) 
                : null,
            'post_id' => !empty($_POST['post_id']) 
                ? intval($_POST['post_id']) 
                : null
        ];
        
        $wpdb->insert($table, $data);
        echo '<div class="notice notice-success"><p>Событие добавлено!</p></div>';
    }
    
    // Обработка обновления события
    if (isset($_POST['update_event']) && check_admin_referer('event_action', 'event_nonce')) {
        $data = [
            'title' => sanitize_text_field($_POST['title']),
            'start_date' => sanitize_text_field($_POST['start_date']),
            'end_date' => sanitize_text_field($_POST['end_date']),
            'description' => sanitize_textarea_field($_POST['description']),
            'color' => sanitize_hex_color($_POST['color']),
            'recurrence' => in_array($_POST['recurrence'], ['none','daily','weekly','monthly']) 
                ? $_POST['recurrence'] 
                : 'none',
            'recurrence_end' => !empty($_POST['recurrence_end']) 
                ? sanitize_text_field($_POST['recurrence_end']) 
                : null,
            'category_id' => !empty($_POST['category_id']) 
                ? intval($_POST['category_id']) 
                : null,
            'post_id' => !empty($_POST['post_id']) 
                ? intval($_POST['post_id']) 
                : null
        ];
        
        $wpdb->update($table, $data, ['id' => intval($_POST['event_id'])]);
        echo '<div class="notice notice-success"><p>Событие обновлено!</p></div>';
    }
    
    // Форма редактирования/добавления
    $event_id = isset($_GET['edit_event']) ? intval($_GET['edit_event']) : 0;
    $event = null;
    if ($event_id) {
        $event = $wpdb->get_row("SELECT * FROM $table WHERE id = $event_id");
    }
    
    $categories = $wpdb->get_results("SELECT * FROM $categories_table");
    $posts_for_events = get_posts([
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'numberposts'    => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'suppress_filters' => false,
    ]);
    ?>
    <div class="wrap">
        <h1><?php echo $event_id ? 'Редактировать' : 'Добавить'; ?> событие</h1>
        <form method="post">
            <?php if ($event_id): ?>
                <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
            <?php endif; ?>
            
            <?php wp_nonce_field('event_action', 'event_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label>Название:</label></th>
                    <td><input type="text" name="title" class="regular-text" value="<?php echo $event ? esc_attr($event->title) : ''; ?>" required></td>
                </tr>
                <tr>
                    <th><label>Дата начала:</label></th>
                    <td><input type="datetime-local" name="start_date" value="<?php echo $event ? str_replace(' ', 'T', substr($event->start_date, 0, 16)) : ''; ?>" required></td>
                </tr>
                <tr>
                    <th><label>Дата окончания:</label></th>
                    <td><input type="datetime-local" name="end_date" value="<?php echo $event && $event->end_date ? str_replace(' ', 'T', substr($event->end_date, 0, 16)) : ''; ?>"></td>
                </tr>
                <tr>
                    <th><label>Описание:</label></th>
                    <td><textarea name="description" class="large-text"><?php echo $event ? esc_textarea($event->description) : ''; ?></textarea></td>
                </tr>
                <tr>
                    <th><label>Цвет:</label></th>
                    <td><input type="color" name="color" value="<?php echo $event ? esc_attr($event->color) : '#3a87ad'; ?>"></td>
                </tr>
                <tr>
                    <th><label>Повторение:</label></th>
                    <td>
                        <select name="recurrence">
                            <option value="none" <?php selected($event ? $event->recurrence : '', 'none'); ?>>Не повторять</option>
                            <option value="daily" <?php selected($event ? $event->recurrence : '', 'daily'); ?>>Ежедневно</option>
                            <option value="weekly" <?php selected($event ? $event->recurrence : '', 'weekly'); ?>>Еженедельно</option>
                            <option value="monthly" <?php selected($event ? $event->recurrence : '', 'monthly'); ?>>Ежемесячно</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Окончание повторений:</label></th>
                    <td><input type="date" name="recurrence_end" value="<?php echo $event && $event->recurrence_end ? esc_attr($event->recurrence_end) : ''; ?>"></td>
                </tr>
                <tr>
                    <th><label>Категория:</label></th>
                    <td>
                        <select name="category_id">
                            <option value="0">-- Без категории --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat->id; ?>" 
                                    <?php selected($event ? $event->category_id : 0, $cat->id); ?>
                                    data-color="<?php echo esc_attr($cat->color); ?>">
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Связанная запись/пост:</label></th>
                    <td>
                        <select name="post_id">
                            <option value="0">-- Без привязки --</option>
                            <?php foreach ($posts_for_events as $p): ?>
                                <option value="<?php echo $p->ID; ?>" <?php selected($event ? $event->post_id : 0, $p->ID); ?>>
                                    <?php echo esc_html('[' . $p->ID . '] ' . get_the_title($p)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Событие будет ссылаться на выбранную запись. В списке отображаются последние 50 опубликованных записей любых типов.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <?php if ($event_id): ?>
                    <input type="submit" name="update_event" class="button button-primary" value="Обновить">
                    <a href="<?php echo admin_url('admin.php?page=calendar-events'); ?>" class="button">Отмена</a>
                <?php else: ?>
                    <input type="submit" name="add_event" class="button button-primary" value="Добавить">
                <?php endif; ?>
            </p>
        </form>
        
        <hr>
        
        <h2>Все события</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Дата начала</th>
                    <th>Дата окончания</th>
                    <th>Категория</th>
                    <th>Повторение</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $events = $wpdb->get_results("
                    SELECT e.*, c.name as category_name 
                    FROM $table e
                    LEFT JOIN $categories_table c ON e.category_id = c.id
                    ORDER BY start_date DESC
                ");
                
                foreach ($events as $event) {
                    echo '<tr>';
                    echo '<td>' . esc_html($event->title) . '</td>';
                    echo '<td>' . date('d.m.Y H:i', strtotime($event->start_date)) . '</td>';
                    echo '<td>' . ($event->end_date ? date('d.m.Y H:i', strtotime($event->end_date)) : '-') . '</td>';
                    echo '<td>' . ($event->category_name ? esc_html($event->category_name) : '-') . '</td>';
                    echo '<td>' . esc_html($event->recurrence) . '</td>';
                    echo '<td>';
                    echo '<a href="' . admin_url('admin.php?page=calendar-events&edit_event=' . $event->id) . '" class="button">Редактировать</a> ';
                    echo '<a href="#" class="button delete-event" data-id="' . $event->id . '">Удалить</a>';
                    echo '</td>';
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        $('.delete-event').click(function(e) {
            e.preventDefault();
            if (confirm('Удалить событие?')) {
                var eventId = $(this).data('id');
                $.post(ajaxurl, {
                    action: 'delete_event',
                    event_id: eventId,
                    nonce: '<?php echo wp_create_nonce('calendar_nonce'); ?>'
                }, function() {
                    location.reload();
                });
            }
        });
    });
    </script>
    <?php
}