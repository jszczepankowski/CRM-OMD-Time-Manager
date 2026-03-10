<?php
/**
 * Plugin Name: CRM OMD Time Manager
 * Description: Rejestracja czasu pracy pracowników dla klientów i projektów, akceptacja wpisów, raporty miesięczne i eksport CSV. Pracownicy mogą edytować swoje oczekujące wpisy.
 * Version: 0.17.0
 * Author: OMD
 * Text Domain: crm-omd-time-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CRM_OMD_Time_Manager
{
    private $wpdb;
    private string $tbl_clients;
    private string $tbl_projects;
    private string $tbl_services;
    private string $tbl_entries;
    private string $tbl_project_costs;

    // Statusy wpisów
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_APPROVED_OFF = 'approved_off';
    const STATUS_REJECTED = 'rejected';

    const PROJECT_STATUS_IN_PROGRESS = 'in_progress';
    const PROJECT_STATUS_TO_INVOICE = 'to_invoice';
    const PROJECT_STATUS_SETTLED = 'settled';

    const ROLE_EMPLOYEE = 'crm_pracownik';
    const ROLE_MANAGER = 'crm_manager';
    const ROLE_LEGACY_EMPLOYEE = 'time_tracker_employee';
    const ROLE_LEGACY_MANAGER = 'time_tracker_manger';

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tbl_clients = $wpdb->prefix . 'crm_omd_clients';
        $this->tbl_projects = $wpdb->prefix . 'crm_omd_projects';
        $this->tbl_services = $wpdb->prefix . 'crm_omd_services';
        $this->tbl_entries = $wpdb->prefix . 'crm_omd_entries';
        $this->tbl_project_costs = $wpdb->prefix . 'crm_omd_project_costs';

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('init', [$this, 'ensure_roles']);

        add_action('admin_post_crm_omd_save_client', [$this, 'handle_save_client']);
        add_action('admin_post_crm_omd_delete_client', [$this, 'handle_delete_client']);
        add_action('admin_post_crm_omd_save_project', [$this, 'handle_save_project']);
        add_action('admin_post_crm_omd_delete_project', [$this, 'handle_delete_project']);
        add_action('admin_post_crm_omd_save_service', [$this, 'handle_save_service']);
        add_action('admin_post_crm_omd_delete_service', [$this, 'handle_delete_service']);
        add_action('admin_post_crm_omd_review_entry', [$this, 'handle_review_entry']);
        add_action('admin_post_crm_omd_save_entry_admin', [$this, 'handle_save_entry_admin']);
        add_action('admin_post_crm_omd_delete_entry', [$this, 'handle_delete_entry']);
        add_action('admin_post_crm_omd_bulk_entries_update', [$this, 'handle_bulk_entries_update']);
        add_action('admin_post_crm_omd_duplicate_fixed_entry', [$this, 'handle_duplicate_fixed_entry']);
        add_action('admin_post_crm_omd_export_report', [$this, 'handle_export_report']);
        add_action('admin_post_crm_omd_save_worker_settings', [$this, 'handle_save_worker_settings']);
        add_action('admin_post_crm_omd_save_reminder_settings', [$this, 'handle_save_reminder_settings']);
        add_action('admin_post_crm_omd_update_worker', [$this, 'handle_update_worker']);
        add_action('admin_post_crm_omd_delete_worker', [$this, 'handle_delete_worker']);
        add_action('admin_post_crm_omd_add_project_cost_front', [$this, 'handle_add_project_cost_front']);
        add_action('admin_post_crm_omd_update_project_status_front', [$this, 'handle_update_project_status_front']);
        add_shortcode('crm_omd_time_tracker', [$this, 'render_tracker_shortcode']);
        add_shortcode('crm_omd_employee_login', [$this, 'render_employee_login_shortcode']);
        add_shortcode('crm_omd_employee_monthly_view', [$this, 'render_employee_monthly_view_shortcode']);
        add_shortcode('crm_omd_employee_projects', [$this, 'render_employee_projects_shortcode']);
        // Zarejestruj akcję dla edycji wpisu
        add_action('admin_post_crm_omd_edit_entry', [$this, 'handle_edit_entry']);
        add_action('admin_post_crm_omd_submit_entry', [$this, 'handle_submit_entry']);
        add_action('crm_omd_daily_reminder', [$this, 'send_daily_reminders']);
        add_action('wp_login', [$this, 'track_user_login'], 10, 2);
        add_filter('login_redirect', [$this, 'filter_login_redirect'], 10, 3);

        // AJAX actions
        add_action('wp_ajax_crm_omd_get_projects', [$this, 'ajax_get_projects']);
        add_action('wp_ajax_crm_omd_get_services', [$this, 'ajax_get_services']);
        add_action('wp_ajax_crm_omd_submit_entry_ajax', [$this, 'ajax_submit_entry']);
        add_action('wp_ajax_crm_omd_get_monthly_table', [$this, 'ajax_get_monthly_table']);

        // Załadowanie stylów i skryptów frontendowych
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);

        // Załadowanie stylów admina
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    // ========== METODY POMOCNICZE ==========
    private function maybe_add_column(string $table, string $column, string $definition): void
    {
        $row = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ));
        if (empty($row)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function get_status_label(string $status): string
    {
        $labels = [
            self::STATUS_PENDING      => 'Oczekuje',
            self::STATUS_APPROVED     => 'Zaakceptowane',
            self::STATUS_APPROVED_OFF => 'Zaakceptowane OFF',
            self::STATUS_REJECTED     => 'Odrzucone',
        ];
        return $labels[$status] ?? $status;
    }

    private function get_available_statuses(bool $include_off = true): array
    {
        $statuses = [
            self::STATUS_PENDING  => $this->get_status_label(self::STATUS_PENDING),
            self::STATUS_APPROVED => $this->get_status_label(self::STATUS_APPROVED),
            self::STATUS_REJECTED => $this->get_status_label(self::STATUS_REJECTED),
        ];
        if ($include_off) {
            $statuses[self::STATUS_APPROVED_OFF] = $this->get_status_label(self::STATUS_APPROVED_OFF);
        }
        return $statuses;
    }

    private function get_project_status_label(string $status): string
    {
        $labels = [
            self::PROJECT_STATUS_IN_PROGRESS => 'W realizacji',
            self::PROJECT_STATUS_TO_INVOICE  => 'Do faktury',
            self::PROJECT_STATUS_SETTLED     => 'Rozliczono',
        ];

        return $labels[$status] ?? $status;
    }

    private function get_project_statuses(): array
    {
        return [
            self::PROJECT_STATUS_IN_PROGRESS => $this->get_project_status_label(self::PROJECT_STATUS_IN_PROGRESS),
            self::PROJECT_STATUS_TO_INVOICE  => $this->get_project_status_label(self::PROJECT_STATUS_TO_INVOICE),
            self::PROJECT_STATUS_SETTLED     => $this->get_project_status_label(self::PROJECT_STATUS_SETTLED),
        ];
    }

    private function user_can_manage_front_projects(int $user_id): bool
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }

        return in_array(self::ROLE_MANAGER, (array) $user->roles, true)
            || in_array(self::ROLE_LEGACY_MANAGER, (array) $user->roles, true)
            || user_can($user, 'manage_options');
    }

    public function ensure_roles(): void
    {
        $employee_role = get_role(self::ROLE_EMPLOYEE);
        if (!$employee_role) {
            $employee_role = get_role(self::ROLE_LEGACY_EMPLOYEE);
        }
        if (!$employee_role) {
            $employee_role = get_role('subscriber');
        }

        $caps = ['read' => true];
        if ($employee_role) {
            $caps = $employee_role->capabilities;
        }

        add_role(self::ROLE_EMPLOYEE, 'CRM_pracownik', $caps);
        add_role(self::ROLE_MANAGER, 'CRM_manager', $caps);

        $this->migrate_legacy_role(self::ROLE_LEGACY_EMPLOYEE, self::ROLE_EMPLOYEE);
        $this->migrate_legacy_role(self::ROLE_LEGACY_MANAGER, self::ROLE_MANAGER);
    }

    private function migrate_legacy_role(string $from_role, string $to_role): void
    {
        $legacy_users = get_users([
            'role' => $from_role,
            'fields' => 'ID',
        ]);

        foreach ($legacy_users as $legacy_user_id) {
            $user = get_user_by('id', (int) $legacy_user_id);
            if (!$user) {
                continue;
            }

            if (!in_array($to_role, (array) $user->roles, true)) {
                $user->add_role($to_role);
            }
            $user->remove_role($from_role);
        }

        if (get_role($from_role)) {
            remove_role($from_role);
        }
    }

    private function get_month_options(string $selected = ''): string {
        $min_date = $this->wpdb->get_var("SELECT MIN(work_date) FROM {$this->tbl_entries}");
        if (!$min_date) {
            $min_date = current_time('Y-m-d');
        }
        $start = strtotime($min_date);
        $end = strtotime(current_time('Y-m-01'));
        $start = strtotime(date('Y-m-01', $start));
        
        $options = '';
        for ($month = $start; $month <= $end; $month = strtotime('+1 month', $month)) {
            $value = date('Y-m', $month);
            $label = date_i18n('F Y', $month);
            $selected_attr = ($value === $selected) ? ' selected' : '';
            $options .= sprintf('<option value="%s"%s>%s</option>', $value, $selected_attr, $label);
        }
        return $options;
    }

    public function enqueue_frontend_styles(): void
    {
        global $post;
        if (is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'crm_omd_time_tracker') ||
            has_shortcode($post->post_content, 'crm_omd_employee_login') ||
            has_shortcode($post->post_content, 'crm_omd_employee_monthly_view') ||
            has_shortcode($post->post_content, 'crm_omd_employee_projects')
        )) {
            wp_register_style(
                'crm-omd-frontend',
                plugins_url('assets/frontend.css', __FILE__),
                [],
                '0.17.0'
            );
            wp_enqueue_style('crm-omd-frontend');
        }
    }

    public function enqueue_frontend_scripts(): void
    {
        global $post;
        if (is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'crm_omd_time_tracker') ||
            has_shortcode($post->post_content, 'crm_omd_employee_monthly_view') ||
            has_shortcode($post->post_content, 'crm_omd_employee_projects')
        )) {
            wp_enqueue_script(
                'crm-omd-frontend',
                plugins_url('assets/frontend.js', __FILE__),
                ['jquery'],
                '0.17.0',
                true
            );
            wp_localize_script('crm-omd-frontend', 'crm_omd_ajax', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('crm_omd_submit_entry')
            ]);
        }
    }

    public function enqueue_admin_styles($hook): void
    {
        if (strpos($hook, 'crm-omd') !== false) {
            wp_register_style(
                'crm-omd-admin',
                plugins_url('assets/admin.css', __FILE__),
                [],
                '0.17.0'
            );
            wp_enqueue_style('crm-omd-admin');
        }
    }

    public function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->wpdb->get_charset_collate();

        // Tabela klientów
        dbDelta("CREATE TABLE {$this->tbl_clients} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            nip VARCHAR(20) NULL,
            phone VARCHAR(20) NULL,
            contact_name VARCHAR(191) NULL,
            contact_email VARCHAR(191) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");

        $this->maybe_add_column($this->tbl_clients, 'full_name', "VARCHAR(191) NULL AFTER name");
        $this->maybe_add_column($this->tbl_clients, 'street', "VARCHAR(191) NULL");
        $this->maybe_add_column($this->tbl_clients, 'building_number', "VARCHAR(20) NULL");
        $this->maybe_add_column($this->tbl_clients, 'postcode', "VARCHAR(10) NULL");
        $this->maybe_add_column($this->tbl_clients, 'city', "VARCHAR(100) NULL");

        // Tabela projektów
        dbDelta("CREATE TABLE {$this->tbl_projects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            budget DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY client_id (client_id)
        ) $charset;");
        $this->maybe_add_column($this->tbl_projects, 'budget', "DECIMAL(10,2) NOT NULL DEFAULT 0");
        $this->maybe_add_column($this->tbl_projects, 'project_status', "VARCHAR(20) NOT NULL DEFAULT 'in_progress'");

        dbDelta("CREATE TABLE {$this->tbl_project_costs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            description VARCHAR(191) NOT NULL,
            cost_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id)
        ) $charset;");

        // Tabela usług
        dbDelta("CREATE TABLE {$this->tbl_services} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            billing_type VARCHAR(20) NOT NULL DEFAULT 'hourly',
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            fixed_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY client_id (client_id)
        ) $charset;");

        // Tabela wpisów
        dbDelta("CREATE TABLE {$this->tbl_entries} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            hours DECIMAL(7,2) NOT NULL DEFAULT 0,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            calculated_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY client_id (client_id),
            KEY project_id (project_id),
            KEY service_id (service_id),
            KEY status (status),
            KEY work_date (work_date)
        ) $charset;");

        $this->ensure_roles();

        if (!wp_next_scheduled('crm_omd_daily_reminder')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'crm_omd_daily_reminder');
        }

        add_option('crm_omd_reminder_mode', 'interval');
        add_option('crm_omd_reminder_interval_days', 5);
        add_option('crm_omd_reminder_day_of_month', 5);
        add_option('crm_omd_last_global_reminder_sent', '1970-01-01');
    }

    public function deactivate(): void
    {
        $timestamp = wp_next_scheduled('crm_omd_daily_reminder');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'crm_omd_daily_reminder');
        }
    }

    private function require_admin_access(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'crm-omd-time-manager'));
        }
    }

    private function recalculate_entry_value(int $client_id, int $service_id, float $hours, float $amount = 0): ?float
    {
        $service = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT billing_type, hourly_rate, fixed_value FROM {$this->tbl_services} WHERE id = %d AND client_id = %d",
            $service_id, $client_id
        ));
        if (!$service) {
            return null;
        }

        if ($service->billing_type === 'fixed') {
            return $amount > 0 ? $amount : (float) $service->fixed_value;
        } else {
            return $hours * (float) $service->hourly_rate;
        }
    }

    public function track_user_login(string $user_login, WP_User $user): void
    {
        update_user_meta($user->ID, 'crm_omd_last_login', current_time('mysql'));
    }

    public function filter_login_redirect(string $redirect_to, string $requested_redirect_to, $user): string
    {
        if (!($user instanceof WP_User)) {
            return $redirect_to;
        }

        if (user_can($user, 'manage_options')) {
            return $redirect_to;
        }

        return home_url('/panel-pracownika/');
    }

    public function render_employee_login_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (is_user_logged_in()) {
            return '<p class="crm-omd-frontend">Jesteś już zalogowany.</p>';
        }

        $atts = shortcode_atts([
            'logo_url' => 'https://maincloud.pl/crm/wp-content/uploads/2026/03/jelen.png',
            'title' => 'Panel logowania pracownika',
            'redirect_to' => home_url('/panel-pracownika/'),
        ], $atts, 'crm_omd_employee_login');

        $redirect_to = !empty($atts['redirect_to']) ? esc_url_raw((string) $atts['redirect_to']) : home_url('/panel-pracownika/');
        $args = [
            'echo' => false,
            'redirect' => $redirect_to,
            'remember' => true,
            'label_username' => 'Login lub e-mail',
            'label_password' => 'Hasło',
            'label_log_in' => 'Zaloguj',
            'form_id' => 'crm-omd-employee-login-form',
        ];

        ob_start();
        echo '<div class="crm-omd-frontend crm-omd-login-panel">';
        if (!empty($atts['logo_url'])) {
            echo '<div style="text-align:center;margin-bottom:1.5em;"><img src="' . esc_url($atts['logo_url']) . '" alt="Logo" style="max-height:180px;width:auto;"></div>';
        } else {
            echo '<div class="crm-omd-logo-placeholder">Miejsce na branding / logo</div>';
        }
        echo '<h3>' . esc_html($atts['title']) . '</h3>';
        echo wp_login_form($args);
        echo '</div>';
        return (string) ob_get_clean();
    }

    public function register_admin_menu(): void
    {
        add_menu_page('CRM OMD Time', 'CRM OMD Time', 'manage_options', 'crm-omd-time', [$this, 'render_entries_page'], 'dashicons-clock', 28);
        add_submenu_page('crm-omd-time', 'Wpisy godzinowe', 'Wpisy godzinowe', 'manage_options', 'crm-omd-time', [$this, 'render_entries_page']);
        add_submenu_page('crm-omd-time', 'Klienci', 'Klienci', 'manage_options', 'crm-omd-clients', [$this, 'render_clients_page']);
        add_submenu_page('crm-omd-time', 'Projekty', 'Projekty', 'manage_options', 'crm-omd-projects', [$this, 'render_projects_page']);
        add_submenu_page('crm-omd-time', 'Usługi', 'Usługi', 'manage_options', 'crm-omd-services', [$this, 'render_services_page']);
        add_submenu_page('crm-omd-time', 'Pracownicy', 'Pracownicy', 'manage_options', 'crm-omd-workers', [$this, 'render_workers_page']);
        add_submenu_page('crm-omd-time', 'Raporty', 'Raporty', 'manage_options', 'crm-omd-reports', [$this, 'render_reports_page']);
    }

    private function get_working_days_in_month(int $year, int $month): int
    {
        $days_in_month = (int) cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $working_days = 0;

        for ($day = 1; $day <= $days_in_month; $day++) {
            $timestamp = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
            $weekday = (int) date('N', $timestamp);
            if ($weekday <= 5) {
                $working_days++;
            }
        }

        return $working_days;
    }

    private function get_month_boundaries(string $month): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $date_from = $month . '-01';
        $date_to = date('Y-m-t', strtotime($date_from));

        return [$date_from, $date_to];
    }

    private function get_user_reported_hours_for_range(int $user_id, string $date_from, string $date_to): float
    {
        $sum = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(hours), 0) FROM {$this->tbl_entries} WHERE user_id = %d AND work_date BETWEEN %s AND %s",
                $user_id,
                $date_from,
                $date_to
            )
        );

        return (float) $sum;
    }

    private function get_user_revenue_for_range(int $user_id, string $date_from, string $date_to): float
    {
        $sum = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COALESCE(SUM(calculated_value), 0)
             FROM {$this->tbl_entries}
             WHERE user_id = %d AND work_date BETWEEN %s AND %s AND status = 'approved'",
            $user_id,
            $date_from,
            $date_to
        ));
        return (float) $sum;
    }

    /**
     * Generuje HTML tabeli z wpisami.
     */
    private function get_monthly_table_html(int $user_id, string $month): string {
        [$date_from, $date_to] = $this->get_month_boundaries($month);
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT e.id, e.work_date, c.name AS client_name, p.name AS project_name, s.name AS service_name, e.hours, e.status, e.description
                FROM {$this->tbl_entries} e
                INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
                INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                WHERE e.user_id = %d AND e.work_date BETWEEN %s AND %s
                ORDER BY e.work_date DESC, e.id DESC",
                $user_id,
                $date_from,
                $date_to
            )
        );

        $portal_url = home_url('/panel-pracownika/');

        ob_start();
        echo '<table class="entries-table">';
        echo '<thead><tr><th>Data</th><th>Klient</th><th>Projekt</th><th>Usługa</th><th>Godziny</th><th>Status</th><th>Opis</th><th>Akcje</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="8">Brak wpisów dla tego miesiąca.</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->work_date) . '</td>';
                echo '<td>' . esc_html($row->client_name) . '</td>';
                echo '<td>' . esc_html($row->project_name) . '</td>';
                echo '<td>' . esc_html($row->service_name) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row->hours, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html($this->get_status_label($row->status)) . '</td>';
                echo '<td>' . esc_html($row->description) . '</td>';
                echo '<td>';
                if ($row->status === self::STATUS_PENDING) {
                    $edit_url = add_query_arg('edit_entry', $row->id, $portal_url);
                    echo '<a href="' . esc_url($edit_url) . '" class="button">Edytuj</a>';
                } else {
                    echo '-';
                }
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }

    /**
     * Generuje tabelę z dziennym podsumowaniem godzin.
     */
    private function get_daily_summary_html(int $user_id, string $month): string {
        [$date_from, $date_to] = $this->get_month_boundaries($month);
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT work_date, SUM(hours) as total_hours
                FROM {$this->tbl_entries}
                WHERE user_id = %d AND work_date BETWEEN %s AND %s
                GROUP BY work_date
                ORDER BY work_date ASC",
                $user_id,
                $date_from,
                $date_to
            )
        );

        $daily_totals = [];
        foreach ($results as $row) {
            $daily_totals[$row->work_date] = (float) $row->total_hours;
        }

        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end->modify('+1 day'));

        ob_start();
        echo '<h3>Podsumowanie dzienne</h3>';
        echo '<table class="daily-summary-table" style="margin-top: 20px; width: 100%; border-collapse: collapse;">';
        echo '<thead><tr><th>Data</th><th>Łączna liczba godzin</th></tr></thead><tbody>';

        $has_any = false;
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            $display_date = date_i18n('d.m.Y', $date->getTimestamp());
            $hours = isset($daily_totals[$date_str]) ? $daily_totals[$date_str] : 0;
            if ($hours > 0) {
                $has_any = true;
            }
            echo '<tr>';
            echo '<td>' . esc_html($display_date) . '</td>';
            echo '<td>' . esc_html(number_format($hours, 2, ',', ' ')) . '</td>';
            echo '</tr>';
        }

        if (!$has_any) {
            echo '<tr><td colspan="2">Brak wpisów w tym miesiącu.</td></tr>';
        }

        echo '</tbody></table>';
        return ob_get_clean();
    }

    public function render_employee_monthly_view_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (!is_user_logged_in()) {
            return '<p class="crm-omd-frontend">Musisz być zalogowany.</p>';
        }

        $allow = get_user_meta(get_current_user_id(), 'crm_omd_worker_enabled', true);
        if ($allow === '0') {
            return '<p class="crm-omd-frontend">Twoje konto jest wyłączone z raportowania czasu pracy.</p>';
        }

        if (isset($_GET['edit_entry']) && is_numeric($_GET['edit_entry'])) {
            $entry_id = (int) $_GET['edit_entry'];
            $entry = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tbl_entries} WHERE id = %d AND user_id = %d AND status = 'pending'",
                $entry_id,
                get_current_user_id()
            ));
            if ($entry) {
                return $this->render_edit_entry_form($entry);
            }
            return '<p class="crm-omd-frontend error">Nie znaleziono wpisu do edycji lub nie masz uprawnień.</p>';
        }

        if (isset($_GET['updated']) && $_GET['updated'] == '1') {
            echo '<div class="crm-omd-frontend success" style="background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border: 1px solid #c3e6cb; border-radius: 4px;">Wpis został zaktualizowany.</div>';
        }

        $atts = shortcode_atts([
            'month' => date('Y-m'),
        ], $atts, 'crm_omd_employee_monthly_view');

        $selected_month = isset($_GET['crm_omd_month']) ? sanitize_text_field(wp_unslash($_GET['crm_omd_month'])) : (string) $atts['month'];
        $month = preg_match('/^\d{4}-\d{2}$/', $selected_month) ? $selected_month : date('Y-m');
        $year = (int) substr($month, 0, 4);
        $month_num = (int) substr($month, 5, 2);
        [$date_from, $date_to] = $this->get_month_boundaries($month);

        $user_id = get_current_user_id();
        $reported_hours = $this->get_user_reported_hours_for_range($user_id, $date_from, $date_to);
        $working_days = $this->get_working_days_in_month($year, $month_num);
        $expected_hours = $working_days * 8;

        $can_manage_projects = $this->user_can_manage_front_projects($user_id);

        $active_tab = isset($_GET['crm_omd_tab']) ? sanitize_key((string) $_GET['crm_omd_tab']) : 'timesheet';
        if (!in_array($active_tab, ['timesheet', 'projects'], true)) {
            $active_tab = 'timesheet';
        }
        if ($active_tab === 'projects' && !$can_manage_projects) {
            $active_tab = 'timesheet';
        }

        ob_start();
        echo '<div class="crm-omd-frontend crm-omd-employee-monthly-view">';
        echo '<div class="crm-omd-tabs">';
        echo '<a class="crm-omd-tab ' . ($active_tab === 'timesheet' ? 'is-active' : '') . '" href="' . esc_url(add_query_arg('crm_omd_tab', 'timesheet')) . '">Ewidencja czasu</a>';
        if ($can_manage_projects) {
            echo '<a class="crm-omd-tab ' . ($active_tab === 'projects' ? 'is-active' : '') . '" href="' . esc_url(add_query_arg('crm_omd_tab', 'projects')) . '">Projekty</a>';
        }
        echo '</div>';

        if ($active_tab === 'projects') {
            echo $this->render_employee_projects_shortcode();
            echo '</div>';
            return (string) ob_get_clean();
        }

        echo '<form method="get" style="margin:1em 0;width: 35%;float: left;margin-right: 5%;">';
        foreach ($_GET as $key => $value) {
            if ($key === 'crm_omd_month') {
                continue;
            }
            if (is_scalar($value)) {
                echo '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">';
            }
        }
        echo '<label>Miesiąc: ';
        echo '<select name="crm_omd_month">';
        echo $this->get_month_options($month);
        echo '</select>';
        echo '</label> ';
        echo '<button type="submit">Pokaż</button>';
        echo '</form>';

        echo '<table class="summary-table" style="width: 60%">';
        echo '<thead><tr><th>Podsumowanie</th><th>Wartość</th></tr></thead><tbody>';
        echo '<tr><td>Zaraportowane godziny</td><td>' . esc_html(number_format($reported_hours, 2, ',', ' ')) . '</td></tr>';
        echo '<tr><td>Godziny w miesiącu</td><td>' . esc_html((string) $expected_hours) . '</td></tr>';
        echo '<tr class="summary-row"><td>Wynik</td><td>' . esc_html(number_format($reported_hours - (float) $expected_hours, 2, ',', ' ')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<div id="crm-omd-monthly-table-container">';
        echo $this->get_monthly_table_html($user_id, $month);
        echo '</div>';

        echo '<div id="crm-omd-daily-summary-container" style="margin-top: 30px;">';
        echo $this->get_daily_summary_html($user_id, $month);
        echo '</div>';

        echo '</div>';
        return (string) ob_get_clean();
    }

    public function render_employee_projects_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (!is_user_logged_in()) {
            return '<p>Musisz być zalogowany.</p>';
        }

        $user_id = get_current_user_id();
        $can_manage = $this->user_can_manage_front_projects($user_id);

        if (!$can_manage) {
            return '<p>Dostęp do projektów mają tylko role CRM_manager i administrator.</p>';
        }

        $projects = $this->wpdb->get_results(
            "SELECT p.id, p.name, p.budget, p.project_status, c.name AS client_name,
                    COALESCE(SUM(e.hours), 0) AS reported_hours
             FROM {$this->tbl_projects} p
             INNER JOIN {$this->tbl_clients} c ON c.id = p.client_id
             LEFT JOIN {$this->tbl_entries} e ON e.project_id = p.id
             WHERE p.is_active = 1
             GROUP BY p.id, p.name, p.budget, p.project_status, c.name
             ORDER BY c.name ASC, p.name ASC"
        );

        $costs_raw = $this->wpdb->get_results("SELECT project_id, COALESCE(SUM(cost_value), 0) AS total_cost FROM {$this->tbl_project_costs} GROUP BY project_id");
        $costs = [];
        foreach ($costs_raw as $cost_row) {
            $costs[(int) $cost_row->project_id] = (float) $cost_row->total_cost;
        }

        $statuses = $this->get_project_statuses();

        ob_start();
        echo '<h3>Projekty</h3>';

        if (isset($_GET['project_updated']) && $_GET['project_updated'] === '1') {
            echo '<p class="crm-omd-project-alert">Projekt został zaktualizowany.</p>';
        }

        echo '<table class="entries-table">';
        echo '<thead><tr><th>Klient</th><th>Projekt</th><th>Status</th><th>Budżet</th><th>Zaraportowane godziny</th><th>Koszty projektu</th><th>Wynik</th></tr></thead><tbody>';
        if (empty($projects)) {
            echo '<tr><td colspan="7">Brak projektów.</td></tr>';
        } else {
            foreach ($projects as $project) {
                $project_id = (int) $project->id;
                $project_cost = $costs[$project_id] ?? 0.0;
                $result = (float) $project->budget - (float) $project->reported_hours - $project_cost;

                echo '<tr>';
                echo '<td>' . esc_html($project->client_name) . '</td>';
                echo '<td>' . esc_html($project->name) . '</td>';
                echo '<td>' . esc_html($this->get_project_status_label((string) $project->project_status)) . '</td>';
                echo '<td>' . esc_html(number_format((float) $project->budget, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format((float) $project->reported_hours, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format((float) $project_cost, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format((float) $result, 2, ',', ' ')) . '</td>';
                echo '</tr>';

                if ($can_manage) {
                    echo '<tr><td colspan="7">';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="crm-omd-project-actions">';
                    wp_nonce_field('crm_omd_update_project_status_' . $project_id);
                    echo '<input type="hidden" name="action" value="crm_omd_update_project_status_front">';
                    echo '<input type="hidden" name="project_id" value="' . $project_id . '">';
                    echo '<label>Status projektu</label>';
                    echo '<select name="project_status">';
                    foreach ($statuses as $status_key => $status_label) {
                        echo '<option value="' . esc_attr($status_key) . '" ' . selected((string) $project->project_status, $status_key, false) . '>' . esc_html($status_label) . '</option>';
                    }
                    echo '</select>';
                    echo '<button type="submit">Zmień status</button>';
                    echo '</form>';

                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="crm-omd-project-actions">';
                    wp_nonce_field('crm_omd_add_project_cost_' . $project_id);
                    echo '<input type="hidden" name="action" value="crm_omd_add_project_cost_front">';
                    echo '<input type="hidden" name="project_id" value="' . $project_id . '">';
                    echo '<label>Dodaj koszt (ryczałt)</label>';
                    echo '<input type="text" name="cost_description" maxlength="191" placeholder="Opis kosztu" required>';
                    echo '<input type="number" name="cost_value" step="0.01" min="0" placeholder="Wartość kosztu" required>';
                    echo '<button type="submit">Dodaj koszt</button>';
                    echo '</form>';
                    echo '</td></tr>';
                }
            }
        }
        echo '</tbody></table>';

        return (string) ob_get_clean();
    }

    /**
     * Formularz edycji wpisu.
     */
    private function render_edit_entry_form($entry): string {
        $clients = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");

        ob_start();
        ?>
        <div class="crm-omd-frontend">
            <h2>Edycja wpisu z dnia <?php echo esc_html($entry->work_date); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-omd-tracker-form">
                <?php wp_nonce_field('crm_omd_edit_entry_' . $entry->id); ?>
                <input type="hidden" name="action" value="crm_omd_edit_entry">
                <input type="hidden" name="entry_id" value="<?php echo (int) $entry->id; ?>">
                
                <p><label>Data wpisu<br><input type="date" name="work_date" value="<?php echo esc_attr($entry->work_date); ?>" required></label></p>
                
                <p><label>Klient<br>
                    <select name="client_id" id="edit_client_id" required>
                        <option value="">- Wybierz klienta -</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int) $client->id; ?>" <?php selected($entry->client_id, $client->id); ?>><?php echo esc_html($client->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
                
                <p><label>Projekt<br>
                    <select name="project_id" id="edit_project_id" required>
                        <option value="">- Wybierz projekt -</option>
                    </select>
                </label></p>
                
                <p><label>lub dodaj nowy projekt<br><input type="text" name="new_project" id="edit_new_project" maxlength="191" placeholder="Nazwa nowego projektu"></label></p>
                
                <p><label>Usługa<br>
                    <select name="service_id" id="edit_service_id" required>
                        <option value="">- Wybierz usługę -</option>
                    </select>
                </label></p>
                
                <p class="crm-omd-field-hours"><label>Liczba godzin<br><input type="number" name="hours" min="0" step="0.25" value="<?php echo esc_attr((string) $entry->hours); ?>" required></label></p>
                
                <p class="crm-omd-field-amount" style="display:none;"><label>Kwota (PLN)<br><input type="number" name="amount" min="0" step="0.01" value="0"></label></p>
                
                <p><label>Opis prac<br><textarea name="description" rows="2" required><?php echo esc_textarea($entry->description); ?></textarea></label></p>
                
                <p>
                    <button type="submit" style="float: left;min-width: 45%;margin-right: 10px;text-align: center;margin-top: 5%;">Zapisz zmiany</button>
                    <a href="<?php echo esc_url(home_url('/panel-pracownika/')); ?>" class="button" style="min-width: 45%;text-align: center;margin-top: 5%;">Anuluj</a>
                </p>
            </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $client = $('#edit_client_id');
            var $project = $('#edit_project_id');
            var $service = $('#edit_service_id');
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var initialClient = $client.val();
            var selectedProject = <?php echo (int) $entry->project_id; ?>;
            var selectedService = <?php echo (int) $entry->service_id; ?>;

            function loadProjects(clientId, selectedProject) {
                $.get(ajaxurl, {
                    action: 'crm_omd_get_projects',
                    client_id: clientId
                }, function(response) {
                    if (response.success) {
                        $project.empty().append('<option value="">Wybierz projekt</option>');
                        $.each(response.data, function(i, project) {
                            var option = $('<option>', { value: project.id, text: project.name });
                            if (selectedProject && project.id == selectedProject) {
                                option.prop('selected', true);
                            }
                            $project.append(option);
                        });
                        $project.prop('disabled', false);
                    }
                }).fail(function() { console.error('Błąd ładowania projektów'); });
            }

            function loadServices(clientId, selectedService) {
                $.get(ajaxurl, {
                    action: 'crm_omd_get_services',
                    client_id: clientId
                }, function(response) {
                    if (response.success) {
                        $service.empty().append('<option value="">Wybierz usługę</option>');
                        $.each(response.data, function(i, service) {
                            var option = $('<option>', { 
                                value: service.id, 
                                text: service.name + ' (' + (service.billing_type === 'fixed' ? 'ryczałt' : 'godzinowa') + ')'
                            });
                            if (selectedService && service.id == selectedService) {
                                option.prop('selected', true);
                            }
                            $service.append(option);
                        });
                        $service.prop('disabled', false);
                    }
                }).fail(function() { console.error('Błąd ładowania usług'); });
            }

            $client.on('change', function() {
                var clientId = $(this).val();
                $project.empty().append('<option value="">Wybierz projekt</option>').prop('disabled', true);
                $service.empty().append('<option value="">Wybierz usługę</option>').prop('disabled', true);
                if (clientId) {
                    loadProjects(clientId, 0);
                    loadServices(clientId, 0);
                }
            });

            if (initialClient) {
                loadProjects(initialClient, selectedProject);
                loadServices(initialClient, selectedService);
            }
        });
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public function render_tracker_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (!is_user_logged_in()) {
            return '<p class="crm-omd-frontend">Musisz być zalogowany.</p>';
        }

        $allow = get_user_meta(get_current_user_id(), 'crm_omd_worker_enabled', true);
        if ($allow === '0') {
            return '<p class="crm-omd-frontend">Twoje konto jest wyłączone z raportowania czasu pracy.</p>';
        }

        $clients = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");

        $user_id = get_current_user_id();
        $last_date = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT MAX(work_date) FROM {$this->tbl_entries} WHERE user_id = %d",
            $user_id
        ));
        if (!$last_date) {
            $last_date = date('Y-m-d');
        }

        ob_start();
        ?>
        <div class="crm-omd-frontend">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-omd-tracker-form">
                <?php wp_nonce_field('crm_omd_submit_entry'); ?>
                <input type="hidden" name="action" value="crm_omd_submit_entry">
                
                <p><label>Data wpisu<br><input type="date" name="work_date" value="<?php echo esc_attr($last_date); ?>" required></label></p>
                
                <p><label>Klient<br>
                    <select name="client_id" required>
                        <option value="">- Wybierz klienta -</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int) $client->id; ?>"><?php echo esc_html($client->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
                
                <p><label>Projekt<br>
                    <select name="project_id" disabled>
                        <option value="">- Wybierz projekt -</option>
                    </select>
                </label></p>
                
                <p><label>lub dodaj nowy projekt<br><input type="text" name="new_project" maxlength="191"></label></p>
                
                <p><label>Usługa<br>
                    <select name="service_id" disabled>
                        <option value="">- Wybierz usługę -</option>
                    </select>
                </label></p>
                
                <p class="crm-omd-field-hours"><label>Liczba godzin<br><input type="number" name="hours" min="0" step="0.25" value="1" required></label></p>
                
                <p class="crm-omd-field-amount" style="display:none;"><label>Kwota (PLN)<br><input type="number" name="amount" min="0" step="0.01" value="0"></label></p>
                
                <p><label>Opis prac<br><textarea name="description" rows="2" required></textarea></label></p>
                
                <p><button type="submit">Zapisz wpis</button></p>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * POPRAWIONA METODA: Edycja wpisu (bez duplikowania)
     */
    public function handle_edit_entry(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Musisz być zalogowany.', 'crm-omd-time-manager'));
        }

        $user_id = get_current_user_id();
        $allowed = get_user_meta($user_id, 'crm_omd_worker_enabled', true);
        if ($allowed === '0') {
            wp_die(esc_html__('Twoje konto nie ma uprawnień do edycji wpisów.', 'crm-omd-time-manager'));
        }

        $entry_id = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        if (!$entry_id) {
            wp_die(esc_html__('Nieprawidłowy wpis.', 'crm-omd-time-manager'));
        }

        check_admin_referer('crm_omd_edit_entry_' . $entry_id);

        $entry = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tbl_entries} WHERE id = %d AND user_id = %d",
            $entry_id,
            $user_id
        ));

        if (!$entry) {
            wp_die(esc_html__('Nie znaleziono wpisu lub brak uprawnień.', 'crm-omd-time-manager'));
        }

        if ($entry->status !== self::STATUS_PENDING) {
            wp_die(esc_html__('Można edytować tylko wpisy oczekujące.', 'crm-omd-time-manager'));
        }

        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $new_project = isset($_POST['new_project']) ? sanitize_text_field(wp_unslash($_POST['new_project'])) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 0;
        $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $work_date = isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '';

        if (!$client_id || !$service_id || !$work_date || !$description) {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        // Ustal projekt: jeśli podano istniejący projekt, używamy go; w przeciwnym razie, jeśli dodano nowy, tworzymy
        if ($project_id > 0) {
            // istniejący projekt – nic nie robimy
        } elseif (!empty($new_project)) {
            $this->wpdb->insert(
                $this->tbl_projects,
                [
                    'client_id' => $client_id,
                    'name' => $new_project,
                    'description' => '',
                    'budget' => 0,
                    'is_active' => 1,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%f', '%d', '%s']
            );
            $project_id = (int) $this->wpdb->insert_id;
        } else {
            wp_die(esc_html__('Wybierz lub dodaj projekt.', 'crm-omd-time-manager'));
        }

        // Sprawdź, czy usługa należy do klienta i oblicz wartość
        $service = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT billing_type, hourly_rate, fixed_value FROM {$this->tbl_services} WHERE id = %d AND client_id = %d",
            $service_id,
            $client_id
        ));
        if (!$service) {
            wp_die(esc_html__('Usługa nie należy do wskazanego klienta.', 'crm-omd-time-manager'));
        }

        if ($service->billing_type === 'fixed') {
            $hours = 0;
            $value = $amount > 0 ? $amount : (float) $service->fixed_value;
        } else {
            $value = $hours * (float) $service->hourly_rate;
        }

        // Aktualizacja wpisu – używamy bezpośredniego update'u
        $updated = $this->wpdb->update(
            $this->tbl_entries,
            [
                'client_id'      => $client_id,
                'project_id'     => $project_id,
                'service_id'     => $service_id,
                'work_date'      => $work_date,
                'hours'          => $hours,
                'description'    => $description,
                'status'         => self::STATUS_PENDING, // reset statusu do pending
                'calculated_value' => $value,
                'reviewed_by'    => null,
                'reviewed_at'    => null,
            ],
            ['id' => $entry_id], // warunek WHERE
            ['%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            // Logowanie błędu (opcjonalnie)
            error_log('CRM OMD: Błąd aktualizacji wpisu ID ' . $entry_id . ' - ' . $this->wpdb->last_error);
            wp_die(esc_html__('Błąd podczas zapisu zmian. Spróbuj ponownie lub skontaktuj się z administratorem.', 'crm-omd-time-manager'));
        }

        // Przekierowanie na panel z komunikatem sukcesu
        wp_redirect(add_query_arg('updated', '1', home_url('/panel-pracownika/')));
        exit;
    }

    public function handle_submit_entry(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Musisz być zalogowany.', 'crm-omd-time-manager'));
        }

        $user_id = get_current_user_id();
        $allowed = get_user_meta($user_id, 'crm_omd_worker_enabled', true);
        if ($allowed === '0') {
            wp_die(esc_html__('Twoje konto nie ma uprawnień do raportowania czasu.', 'crm-omd-time-manager'));
        }

        check_admin_referer('crm_omd_submit_entry');

        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $new_project = isset($_POST['new_project']) ? sanitize_text_field(wp_unslash($_POST['new_project'])) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 0;
        $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $work_date = isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '';

        if (!$client_id || !$service_id || !$work_date || !$description) {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        if (!$project_id && $new_project !== '') {
            $this->wpdb->insert(
                $this->tbl_projects,
                ['client_id' => $client_id, 'name' => $new_project, 'description' => '', 'budget' => 0, 'is_active' => 1, 'created_at' => current_time('mysql')],
                ['%d', '%s', '%s', '%f', '%d', '%s']
            );
            $project_id = (int) $this->wpdb->insert_id;
        }

        if (!$project_id) {
            wp_die(esc_html__('Wybierz lub dodaj projekt.', 'crm-omd-time-manager'));
        }

        $value = $this->recalculate_entry_value($client_id, $service_id, $hours, $amount);
        if ($value === null) {
            wp_die(esc_html__('Usługa nie należy do wskazanego klienta.', 'crm-omd-time-manager'));
        }

        $service = $this->wpdb->get_row($this->wpdb->prepare("SELECT billing_type FROM {$this->tbl_services} WHERE id = %d", $service_id));
        if ($service && $service->billing_type === 'fixed') {
            $hours = 0;
        }

        $this->wpdb->insert(
            $this->tbl_entries,
            [
                'user_id' => $user_id,
                'client_id' => $client_id,
                'project_id' => $project_id,
                'service_id' => $service_id,
                'work_date' => $work_date,
                'hours' => $hours,
                'description' => $description,
                'status' => self::STATUS_PENDING,
                'calculated_value' => $value,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%s']
        );

        wp_redirect(home_url('/panel-pracownika/'));
        exit;
    }


    public function handle_update_project_status_front(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Musisz być zalogowany.', 'crm-omd-time-manager'));
        }

        $user_id = get_current_user_id();
        if (!$this->user_can_manage_front_projects($user_id)) {
            wp_die(esc_html__('Brak uprawnień do edycji projektu.', 'crm-omd-time-manager'));
        }

        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $status = isset($_POST['project_status']) ? sanitize_text_field(wp_unslash($_POST['project_status'])) : '';
        if ($project_id <= 0) {
            wp_die(esc_html__('Nieprawidłowy projekt.', 'crm-omd-time-manager'));
        }

        check_admin_referer('crm_omd_update_project_status_' . $project_id);

        if (!array_key_exists($status, $this->get_project_statuses())) {
            wp_die(esc_html__('Nieprawidłowy status projektu.', 'crm-omd-time-manager'));
        }

        $this->wpdb->update(
            $this->tbl_projects,
            ['project_status' => $status],
            ['id' => $project_id],
            ['%s'],
            ['%d']
        );

        wp_safe_redirect(add_query_arg(['crm_omd_tab' => 'projects', 'project_updated' => '1'], home_url('/panel-pracownika/')));
        exit;
    }

    public function handle_add_project_cost_front(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Musisz być zalogowany.', 'crm-omd-time-manager'));
        }

        $user_id = get_current_user_id();
        if (!$this->user_can_manage_front_projects($user_id)) {
            wp_die(esc_html__('Brak uprawnień do dodawania kosztów projektu.', 'crm-omd-time-manager'));
        }

        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $description = isset($_POST['cost_description']) ? sanitize_text_field(wp_unslash($_POST['cost_description'])) : '';
        $cost_value = isset($_POST['cost_value']) ? (float) $_POST['cost_value'] : 0;

        if ($project_id <= 0 || $description === '' || $cost_value < 0) {
            wp_die(esc_html__('Wypełnij poprawnie dane kosztu.', 'crm-omd-time-manager'));
        }

        check_admin_referer('crm_omd_add_project_cost_' . $project_id);

        $this->wpdb->insert(
            $this->tbl_project_costs,
            [
                'project_id' => $project_id,
                'description' => $description,
                'cost_value' => $cost_value,
                'created_by' => $user_id,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%d', '%s']
        );

        wp_safe_redirect(add_query_arg(['crm_omd_tab' => 'projects', 'project_updated' => '1'], home_url('/panel-pracownika/')));
        exit;
    }

    /* ======================== */
    /*  METODY AJAX             */
    /* ======================== */

    public function ajax_get_projects(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error('Nie jesteś zalogowany.');
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, 'crm_omd_worker_enabled', true) === '0') {
            wp_send_json_error('Brak uprawnień do raportowania czasu.');
        }

        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        if (!$client_id) {
            wp_send_json_error('Nie podano klienta.');
        }

        $projects = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, name FROM {$this->tbl_projects} WHERE client_id = %d AND is_active = 1 ORDER BY name ASC",
            $client_id
        ));

        wp_send_json_success($projects);
    }

    public function ajax_get_services(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error('Nie jesteś zalogowany.');
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, 'crm_omd_worker_enabled', true) === '0') {
            wp_send_json_error('Brak uprawnień do raportowania czasu.');
        }

        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        if (!$client_id) {
            wp_send_json_error('Nie podano klienta.');
        }

        $services = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, name, billing_type FROM {$this->tbl_services} WHERE client_id = %d AND is_active = 1 ORDER BY name ASC",
            $client_id
        ));

        wp_send_json_success($services);
    }

    public function ajax_submit_entry(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error('Musisz być zalogowany.');
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, 'crm_omd_worker_enabled', true) === '0') {
            wp_send_json_error('Twoje konto nie ma uprawnień do raportowania czasu.');
        }

        if (!check_ajax_referer('crm_omd_submit_entry', 'nonce', false)) {
            wp_send_json_error('Błąd bezpieczeństwa.');
        }

        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $new_project = isset($_POST['new_project']) ? sanitize_text_field(wp_unslash($_POST['new_project'])) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 0;
        $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $work_date = isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '';

        if (!$client_id || !$service_id || !$work_date || !$description) {
            wp_send_json_error('Wypełnij wymagane pola.');
        }

        if (!$project_id && $new_project !== '') {
            $this->wpdb->insert(
                $this->tbl_projects,
                [
                    'client_id' => $client_id,
                    'name' => $new_project,
                    'description' => '',
                    'budget' => 0,
                    'is_active' => 1,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%f', '%d', '%s']
            );
            $project_id = (int) $this->wpdb->insert_id;
        }

        if (!$project_id) {
            wp_send_json_error('Wybierz lub dodaj projekt.');
        }

        $service = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT billing_type, hourly_rate, fixed_value FROM {$this->tbl_services} WHERE id = %d AND client_id = %d",
            $service_id, $client_id
        ));
        if (!$service) {
            wp_send_json_error('Usługa nie istnieje.');
        }

        if ($service->billing_type === 'fixed') {
            $hours = 0;
            $value = $amount > 0 ? $amount : (float) $service->fixed_value;
        } else {
            $value = $hours * (float) $service->hourly_rate;
        }

        $inserted = $this->wpdb->insert(
            $this->tbl_entries,
            [
                'user_id' => $user_id,
                'client_id' => $client_id,
                'project_id' => $project_id,
                'service_id' => $service_id,
                'work_date' => $work_date,
                'hours' => $hours,
                'description' => $description,
                'status' => self::STATUS_PENDING,
                'calculated_value' => $value,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%s']
        );

        if (!$inserted) {
            wp_send_json_error('Błąd zapisu do bazy danych.');
        }

        wp_send_json_success('Wpis dodany pomyślnie.');
    }

    public function ajax_get_monthly_table(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error('Nie jesteś zalogowany.');
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, 'crm_omd_worker_enabled', true) === '0') {
            wp_send_json_error('Brak uprawnień.');
        }

        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $html = $this->get_monthly_table_html($user_id, $month);
        wp_send_json_success($html);
    }

    /* ======================== */
    /*  STRONY ADMINISTRACYJNE  */
    /* ======================== */

    public function render_entries_page(): void
    {
        $this->require_admin_access();

        $order_by = isset($_GET['order_by']) ? sanitize_text_field(wp_unslash($_GET['order_by'])) : 'work_date';
        $order_dir = isset($_GET['order_dir']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['order_dir']))) : 'DESC';
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;

        $allowed_order_by = [
            'work_date' => 'e.work_date',
            'status' => 'e.status',
            'worker' => 'u.display_name',
            'client' => 'c.name',
            'created_at' => 'e.created_at',
        ];
        $sql_order_by = $allowed_order_by[$order_by] ?? 'e.work_date';
        $sql_order_dir = $order_dir === 'ASC' ? 'ASC' : 'DESC';

        $where = 'WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND e.status = %s';
            $params[] = $status;
        }
        if ($user_id > 0) {
            $where .= ' AND e.user_id = %d';
            $params[] = $user_id;
        }
        if ($client_id > 0) {
            $where .= ' AND e.client_id = %d';
            $params[] = $client_id;
        }

        $sql = "SELECT e.id, e.user_id, e.client_id, e.project_id, e.service_id, e.work_date, e.hours, e.description, e.status, e.calculated_value, c.name AS client_name, p.name AS project_name, s.name AS service_name, s.billing_type, u.display_name
            FROM {$this->tbl_entries} e
            INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
            INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
            INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
            INNER JOIN {$this->wpdb->users} u ON u.ID = e.user_id
            {$where}
            ORDER BY {$sql_order_by} {$sql_order_dir}, e.id DESC
            LIMIT 300";
        $rows = empty($params) ? $this->wpdb->get_results($sql) : $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params));

        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        $clients = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");

        $edit_entry_id = isset($_GET['edit_entry']) ? (int) $_GET['edit_entry'] : 0;
        $edit_entry = null;
        if ($edit_entry_id > 0) {
            $edit_entry = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tbl_entries} WHERE id = %d", $edit_entry_id));
        }

        $statuses = $this->get_available_statuses(true);

        echo '<div class="wrap">';
        echo '<h1>Wpisy godzinowe</h1>';

        if ($edit_entry) {
            echo '<div class="crm-omd-admin-card">';
            echo '<h2>Edycja wpisu #' . (int) $edit_entry->id . '</h2>';
            $this->render_entry_form($edit_entry, $users, $clients, $statuses);
            echo '</div>';
        } else {
            echo '<div class="crm-omd-admin-card">';
            echo '<h2>Dodaj wpis jako administrator</h2>';
            $create_defaults = (object) [
                'id' => 0,
                'user_id' => get_current_user_id(),
                'client_id' => 0,
                'project_id' => 0,
                'service_id' => 0,
                'work_date' => date('Y-m-d'),
                'hours' => 1,
                'status' => self::STATUS_APPROVED,
                'description' => '',
            ];
            $this->render_entry_form($create_defaults, $users, $clients, $statuses);
            echo '</div>';
        }

        // Filtry
        echo '<div class="crm-omd-filters-bar">';
        echo '<form method="get" style="display: contents;">';
        echo '<input type="hidden" name="page" value="crm-omd-time">';
        echo '<label>Status: <select name="status">';
        echo '<option value="">Wszystkie</option>';
        foreach ($statuses as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Pracownik: <select name="user_id">';
        echo '<option value="0">Wszyscy</option>';
        foreach ($users as $user) {
            echo '<option value="' . (int) $user->ID . '"' . selected($user_id, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Klient: <select name="client_id">';
        echo '<option value="0">Wszyscy</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '"' . selected($client_id, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Sortuj wg: <select name="order_by">';
        echo '<option value="work_date"' . selected($order_by, 'work_date', false) . '>Data pracy</option>';
        echo '<option value="status"' . selected($order_by, 'status', false) . '>Status</option>';
        echo '<option value="worker"' . selected($order_by, 'worker', false) . '>Pracownik</option>';
        echo '<option value="client"' . selected($order_by, 'client', false) . '>Klient</option>';
        echo '<option value="created_at"' . selected($order_by, 'created_at', false) . '>Data dodania</option>';
        echo '</select></label>';

        echo '<label>Kierunek: <select name="order_dir">';
        echo '<option value="DESC"' . selected($order_dir, 'DESC', false) . '>Malejąco</option>';
        echo '<option value="ASC"' . selected($order_dir, 'ASC', false) . '>Rosnąco</option>';
        echo '</select></label>';

        echo '<button class="button button-primary" type="submit">Filtruj</button>';
        echo '</form>';
        echo '</div>';

        // Tabela wpisów + akcje masowe
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_bulk_entries_update');
        echo '<input type="hidden" name="action" value="crm_omd_bulk_entries_update">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? admin_url('admin.php?page=crm-omd-time'))) . '">';
        echo '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">';
        echo '<select name="bulk_action">';
        echo '<option value="">Akcje masowe</option>';
        echo '<option value="delete">Usuń zaznaczone</option>';
        foreach ($statuses as $value => $label) {
            echo '<option value="status:' . esc_attr($value) . '">Ustaw status: ' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="button">Wykonaj</button>';
        echo '</div>';
        echo '<table class="widefat striped crm-omd-table">';
        echo '<thead><tr>';
        echo '<th><input type="checkbox" id="crm-omd-select-all" aria-label="Zaznacz wszystkie"></th><th>ID</th><th>Data</th><th>Pracownik</th><th>Klient</th><th>Projekt</th><th>Usługa</th><th>Godziny</th><th>Wartość</th><th>Status</th><th>Opis</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td><input type="checkbox" name="entry_ids[]" value="' . (int) $row->id . '"></td>';
            echo '<td>' . (int) $row->id . '</td>';
            echo '<td>' . esc_html($row->work_date) . '</td>';
            echo '<td>' . esc_html($row->display_name) . '</td>';
            echo '<td>' . esc_html($row->client_name) . '</td>';
            echo '<td>' . esc_html($row->project_name) . '</td>';
            echo '<td>' . esc_html($row->service_name) . '</td>';
            echo '<td>' . esc_html((string) $row->hours) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row->calculated_value, 2, ',', ' ')) . ' PLN</td>';
            echo '<td>' . esc_html($this->get_status_label($row->status)) . '</td>';
            echo '<td>' . esc_html($row->description) . '</td>';
            echo '<td>';
            if ($row->status === self::STATUS_PENDING) {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_review_entry&id=' . (int) $row->id . '&decision=approved'), 'crm_omd_review_entry_' . (int) $row->id)) . '">Akceptuj</a> ';
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_review_entry&id=' . (int) $row->id . '&decision=rejected'), 'crm_omd_review_entry_' . (int) $row->id)) . '">Odrzuć</a> ';
            }
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-time', 'edit_entry' => (int) $row->id], admin_url('admin.php'))) . '">Edytuj</a> ';
            if ($row->billing_type === 'fixed') {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_duplicate_fixed_entry&id=' . (int) $row->id), 'crm_omd_duplicate_fixed_entry_' . (int) $row->id)) . '">Duplikuj ryczałt</a> ';
            }
            echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_entry&id=' . (int) $row->id), 'crm_omd_delete_entry_' . (int) $row->id)) . '" onclick="return confirm(\'Na pewno usunąć wpis?\');">Usuń</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</form>';
        echo '</div>';

        // Skrypt AJAX dla formularzy admina
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $client = $('select[name="client_id"]');
            var $project = $('select[name="project_id"]');
            var $service = $('select[name="service_id"]');
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

            function loadProjects(clientId, selectedProject) {
                $.get(ajaxurl, {
                    action: 'crm_omd_get_projects',
                    client_id: clientId
                }, function(response) {
                    if (response.success) {
                        $project.empty().append('<option value="">Wybierz projekt</option>');
                        $.each(response.data, function(i, project) {
                            var option = $('<option>', { value: project.id, text: project.name });
                            if (selectedProject && project.id == selectedProject) {
                                option.prop('selected', true);
                            }
                            $project.append(option);
                        });
                        $project.prop('disabled', false);
                    }
                }).fail(function() { console.error('Błąd ładowania projektów'); });
            }

            function loadServices(clientId, selectedService) {
                $.get(ajaxurl, {
                    action: 'crm_omd_get_services',
                    client_id: clientId
                }, function(response) {
                    if (response.success) {
                        $service.empty().append('<option value="">Wybierz usługę</option>');
                        $.each(response.data, function(i, service) {
                            var option = $('<option>', { 
                                value: service.id, 
                                text: service.name + ' (' + (service.billing_type === 'fixed' ? 'ryczałt' : 'godzinowa') + ')'
                            });
                            if (selectedService && service.id == selectedService) {
                                option.prop('selected', true);
                            }
                            $service.append(option);
                        });
                        $service.prop('disabled', false);
                    }
                }).fail(function() { console.error('Błąd ładowania usług'); });
            }

            $('#crm-omd-select-all').on('change', function() {
                $('input[name="entry_ids[]"]').prop('checked', $(this).is(':checked'));
            });

            $client.on('change', function() {
                var clientId = $(this).val();
                $project.empty().append('<option value="">Wybierz projekt</option>').prop('disabled', true);
                $service.empty().append('<option value="">Wybierz usługę</option>').prop('disabled', true);
                if (clientId) {
                    loadProjects(clientId, 0);
                    loadServices(clientId, 0);
                }
            });

            var initialClient = $client.val();
            if (initialClient) {
                loadProjects(initialClient, <?php echo (int) ($edit_entry ? $edit_entry->project_id : 0); ?>);
                loadServices(initialClient, <?php echo (int) ($edit_entry ? $edit_entry->service_id : 0); ?>);
            }
        });
        </script>
        <?php
    }

    /**
     * Pomocnicza funkcja do rysowania formularza wpisu (admin).
     */
    private function render_entry_form($entry, $users, $clients, array $statuses): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_save_entry_admin_' . (int) $entry->id); ?>
            <input type="hidden" name="action" value="crm_omd_save_entry_admin">
            <input type="hidden" name="id" value="<?php echo (int) $entry->id; ?>">

            <div class="crm-omd-form-grid">
                <div class="form-field">
                    <label for="user_id">Pracownik</label>
                    <select name="user_id" id="user_id" required>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo (int) $user->ID; ?>" <?php selected((int) $entry->user_id, (int) $user->ID); ?>>
                                <?php echo esc_html($user->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="client_id">Klient</label>
                    <select name="client_id" id="client_id" required>
                        <option value="">Wybierz klienta</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int) $client->id; ?>" <?php selected((int) $entry->client_id, (int) $client->id); ?>>
                                <?php echo esc_html($client->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="project_id">Projekt</label>
                    <select name="project_id" id="project_id" required>
                        <option value="">Wybierz projekt</option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="new_project">lub dodaj nowy projekt</label>
                    <input type="text" name="new_project" id="new_project" maxlength="191" placeholder="Nazwa nowego projektu">
                </div>

                <div class="form-field">
                    <label for="service_id">Usługa</label>
                    <select name="service_id" id="service_id" required>
                        <option value="">Wybierz usługę</option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="work_date">Data pracy</label>
                    <input type="date" name="work_date" id="work_date" value="<?php echo esc_attr($entry->work_date); ?>" required>
                </div>

                <div class="form-field">
                    <label for="hours">Godziny</label>
                    <input type="number" name="hours" id="hours" min="0" step="0.25" value="<?php echo esc_attr((string) $entry->hours); ?>" required>
                </div>

                <div class="form-field">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($entry->status, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field" style="grid-column: span 2;">
                    <label for="description">Opis</label>
                    <textarea name="description" id="description" rows="4" required><?php echo esc_textarea($entry->description); ?></textarea>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $entry->id ? 'Zapisz zmiany' : 'Dodaj wpis'; ?></button>
                <?php if ($entry->id): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crm-omd-time')); ?>" class="button" >Anuluj</a>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }

    public function handle_review_entry(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $decision = isset($_GET['decision']) ? sanitize_text_field(wp_unslash($_GET['decision'])) : '';
        check_admin_referer('crm_omd_review_entry_' . $id);

        if (!$id || !in_array($decision, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            wp_die(esc_html__('Niepoprawne dane.', 'crm-omd-time-manager'));
        }

        $this->wpdb->update(
            $this->tbl_entries,
            ['status' => $decision, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_save_entry_admin(): void
    {
        $this->require_admin_access();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('crm_omd_save_entry_admin_' . $id);

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $new_project = isset($_POST['new_project']) ? sanitize_text_field(wp_unslash($_POST['new_project'])) : '';
        $work_date = isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : self::STATUS_PENDING;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

        $allowed_statuses = array_keys($this->get_available_statuses(true));
        if (!$user_id || !$client_id || !$service_id || !$work_date || !in_array($status, $allowed_statuses, true)) {
            wp_die(esc_html__('Niepoprawne dane formularza.', 'crm-omd-time-manager'));
        }

        if (!$project_id && $new_project !== '') {
            $this->wpdb->insert(
                $this->tbl_projects,
                [
                    'client_id' => $client_id,
                    'name' => $new_project,
                    'description' => '',
                    'budget' => 0,
                    'is_active' => 1,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%f', '%d', '%s']
            );
            $project_id = (int) $this->wpdb->insert_id;
        }

        if (!$project_id) {
            wp_die(esc_html__('Wybierz lub dodaj projekt.', 'crm-omd-time-manager'));
        }

        $value = $this->recalculate_entry_value($client_id, $service_id, $hours);
        if ($value === null) {
            wp_die(esc_html__('Usługa nie należy do wskazanego klienta.', 'crm-omd-time-manager'));
        }

        $data = [
            'user_id' => $user_id,
            'client_id' => $client_id,
            'project_id' => $project_id,
            'service_id' => $service_id,
            'work_date' => $work_date,
            'hours' => $hours,
            'description' => $description,
            'status' => $status,
            'calculated_value' => $value,
            'reviewed_by' => in_array($status, [self::STATUS_PENDING]) ? null : get_current_user_id(),
            'reviewed_at' => in_array($status, [self::STATUS_PENDING]) ? null : current_time('mysql'),
        ];

        if ($id > 0) {
            $this->wpdb->update(
                $this->tbl_entries,
                $data,
                ['id' => $id],
                ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%d', '%s'],
                ['%d']
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert(
                $this->tbl_entries,
                $data,
                ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%d', '%s', '%s']
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_duplicate_fixed_entry(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_duplicate_fixed_entry_' . $id);

        if ($id <= 0) {
            wp_die(esc_html__('Niepoprawny wpis.', 'crm-omd-time-manager'));
        }

        $entry = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tbl_entries} WHERE id = %d", $id));
        if (!$entry) {
            wp_die(esc_html__('Nie znaleziono wpisu.', 'crm-omd-time-manager'));
        }

        $service = $this->wpdb->get_row($this->wpdb->prepare("SELECT billing_type FROM {$this->tbl_services} WHERE id = %d", (int) $entry->service_id));
        if (!$service || $service->billing_type !== 'fixed') {
            wp_die(esc_html__('Duplikowanie dostępne tylko dla wpisów ryczałtowych.', 'crm-omd-time-manager'));
        }

        $this->wpdb->insert(
            $this->tbl_entries,
            [
                'user_id' => (int) $entry->user_id,
                'client_id' => (int) $entry->client_id,
                'project_id' => (int) $entry->project_id,
                'service_id' => (int) $entry->service_id,
                'work_date' => current_time('Y-m-d'),
                'hours' => (float) $entry->hours,
                'description' => (string) $entry->description,
                'status' => self::STATUS_PENDING,
                'calculated_value' => (float) $entry->calculated_value,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%s']
        );

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_delete_entry(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_entry_' . $id);

        $this->wpdb->delete($this->tbl_entries, ['id' => $id], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_bulk_entries_update(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_bulk_entries_update');

        $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';
        $entry_ids = isset($_POST['entry_ids']) && is_array($_POST['entry_ids']) ? array_map('intval', wp_unslash($_POST['entry_ids'])) : [];
        $entry_ids = array_values(array_filter($entry_ids, static function ($id) {
            return $id > 0;
        }));

        $return_url_raw = isset($_POST['return_url']) ? sanitize_text_field(wp_unslash($_POST['return_url'])) : '';
        $return_url = admin_url('admin.php?page=crm-omd-time');
        if ($return_url_raw !== '') {
            $return_url = wp_validate_redirect($return_url_raw, $return_url);
        }

        if ($bulk_action === '' || empty($entry_ids)) {
            wp_safe_redirect($return_url);
            exit;
        }

        $placeholders = implode(', ', array_fill(0, count($entry_ids), '%d'));

        if ($bulk_action === 'delete') {
            $sql = "DELETE FROM {$this->tbl_entries} WHERE id IN ({$placeholders})";
            $this->wpdb->query($this->wpdb->prepare($sql, ...$entry_ids));
            wp_safe_redirect($return_url);
            exit;
        }

        if (strpos($bulk_action, 'status:') === 0) {
            $new_status = substr($bulk_action, 7);
            $allowed_statuses = array_keys($this->get_available_statuses(true));
            if (in_array($new_status, $allowed_statuses, true)) {
                if ($new_status === self::STATUS_PENDING) {
                    $sql = "UPDATE {$this->tbl_entries}
                            SET status = %s,
                                reviewed_by = NULL,
                                reviewed_at = NULL
                            WHERE id IN ({$placeholders})";
                    $params = array_merge([$new_status], $entry_ids);
                } else {
                    $sql = "UPDATE {$this->tbl_entries}
                            SET status = %s,
                                reviewed_by = %d,
                                reviewed_at = %s
                            WHERE id IN ({$placeholders})";
                    $params = array_merge([$new_status, get_current_user_id(), current_time('mysql')], $entry_ids);
                }
                $this->wpdb->query($this->wpdb->prepare($sql, ...$params));
            }
        }

        wp_safe_redirect($return_url);
        exit;
    }

    public function render_clients_page(): void
    {
        $this->require_admin_access();

        // Sortowanie
        $order_by = isset($_GET['order_by']) ? sanitize_text_field(wp_unslash($_GET['order_by'])) : 'name';
        $order_dir = isset($_GET['order_dir']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['order_dir']))) : 'ASC';

        $allowed_order = ['name', 'nip', 'phone', 'contact_name', 'contact_email', 'is_active'];
        if (!in_array($order_by, $allowed_order, true)) {
            $order_by = 'name';
        }
        $order_dir = ($order_dir === 'DESC') ? 'DESC' : 'ASC';

        // NOWE: pobieramy również nowe kolumny
        $rows = $this->wpdb->get_results("SELECT id, name, full_name, street, building_number, postcode, city, nip, phone, contact_name, contact_email, is_active FROM {$this->tbl_clients} ORDER BY {$order_by} {$order_dir}");

        $edit_id = isset($_GET['edit_client']) ? (int) $_GET['edit_client'] : 0;
        $edit = $edit_id ? $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tbl_clients} WHERE id = %d", $edit_id)) : null;

        echo '<div class="wrap">';
        echo '<h1>Klienci</h1>';

        // Formularz dodawania/edycji
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>' . ($edit ? 'Edytuj klienta' : 'Dodaj klienta') . '</h2>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_save_client'); ?>
            <input type="hidden" name="action" value="crm_omd_save_client">
            <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">

            <div class="crm-omd-form-grid">
                <div class="form-field">
                    <label for="name">Nazwa klienta *</label>
                    <input type="text" name="name" id="name" value="<?php echo $edit ? esc_attr($edit->name) : ''; ?>" required>
                </div>

                <!-- NOWE POLE: Pełna nazwa firmy -->
                <div class="form-field">
                    <label for="full_name">Pełna nazwa firmy (opcjonalnie)</label>
                    <input type="text" name="full_name" id="full_name" value="<?php echo $edit ? esc_attr((string) $edit->full_name) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="nip">NIP</label>
                    <input type="text" name="nip" id="nip" value="<?php echo $edit ? esc_attr((string) $edit->nip) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="phone">Numer telefonu</label>
                    <input type="text" name="phone" id="phone" value="<?php echo $edit ? esc_attr((string) $edit->phone) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="contact_name">Osoba kontaktowa</label>
                    <input type="text" name="contact_name" id="contact_name" value="<?php echo $edit ? esc_attr((string) $edit->contact_name) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="contact_email">Email kontaktowy</label>
                    <input type="email" name="contact_email" id="contact_email" value="<?php echo $edit ? esc_attr((string) $edit->contact_email) : ''; ?>">
                </div>

                <!-- NOWE POLE: Adres -->
                <div class="form-field">
                    <label for="street">Ulica</label>
                    <input type="text" name="street" id="street" value="<?php echo $edit ? esc_attr((string) $edit->street) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="building_number">Nr budynku</label>
                    <input type="text" name="building_number" id="building_number" value="<?php echo $edit ? esc_attr((string) $edit->building_number) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="postcode">Kod pocztowy</label>
                    <input type="text" name="postcode" id="postcode" value="<?php echo $edit ? esc_attr((string) $edit->postcode) : ''; ?>">
                </div>

                <div class="form-field">
                    <label for="city">Miejscowość</label>
                    <input type="text" name="city" id="city" value="<?php echo $edit ? esc_attr((string) $edit->city) : ''; ?>">
                </div>

                <div class="form-field" style="display: flex; align-items: center;background: #d0a46c;border-color: #b58b54;color: #212123;padding: 12px 10px 10px 10px;margin-top: 24px">
                    <label style="margin-right: 10px;">
                        <input type="checkbox" name="is_active" value="1" <?php checked($edit ? (int) $edit->is_active : 1, 1); ?>>
                        Aktywny
                    </label>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Zapisz zmiany' : 'Dodaj klienta'; ?></button>
                <?php if ($edit): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crm-omd-clients')); ?>" class="button">Anuluj</a>
                <?php endif; ?>
            </p>
        </form>
        <?php
        echo '</div>'; // .crm-omd-admin-card

        // Lista klientów
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>Lista klientów</h2>';
        echo '<table class="widefat striped crm-omd-table">';
        echo '<thead><tr>';
        echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'name', 'order_dir' => ($order_by === 'name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')])) . '">Nazwa</a></th>';
        // NOWE: nagłówki dla nowych pól
        echo '<th>Pełna nazwa</th>';
        echo '<th>Adres</th>';
        echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'nip', 'order_dir' => ($order_by === 'nip' && $order_dir === 'ASC' ? 'DESC' : 'ASC')])) . '">NIP</a></th>';
        echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'phone', 'order_dir' => ($order_by === 'phone' && $order_dir === 'ASC' ? 'DESC' : 'ASC')])) . '">Telefon</a></th>';
        echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'contact_name', 'order_dir' => ($order_by === 'contact_name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')])) . '">Osoba kontaktowa</a></th>';
        echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'contact_email', 'order_dir' => ($order_by === 'contact_email' && $order_dir === 'ASC' ? 'DESC' : 'ASC')])) . '">Email</a></th>';
        echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'is_active', 'order_dir' => ($order_by === 'is_active' && $order_dir === 'ASC' ? 'DESC' : 'ASC')])) . '">Status</a></th>';
        echo '<th>Akcje</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            // NOWE: składamy adres
            $address = trim($row->street . ' ' . $row->building_number . ', ' . $row->postcode . ' ' . $row->city);
            echo '<tr>';
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>' . esc_html((string) $row->full_name) . '</td>'; // NOWE
            echo '<td>' . esc_html($address) . '</td>'; // NOWE
            echo '<td>' . esc_html((string) $row->nip) . '</td>';
            echo '<td>' . esc_html((string) $row->phone) . '</td>';
            echo '<td>' . esc_html((string) $row->contact_name) . '</td>';
            echo '<td>' . esc_html((string) $row->contact_email) . '</td>';
            echo '<td>' . ((int) $row->is_active ? 'Aktywny' : 'Nieaktywny') . '</td>';
            echo '<td>';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-clients', 'edit_client' => (int) $row->id], admin_url('admin.php'))) . '">Edytuj</a> ';
            echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_client&id=' . (int) $row->id), 'crm_omd_delete_client_' . (int) $row->id)) . '">Dezaktywuj</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // .crm-omd-admin-card
        echo '</div>'; // .wrap
    }

    public function handle_save_client(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_client');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $full_name = isset($_POST['full_name']) ? sanitize_text_field(wp_unslash($_POST['full_name'])) : ''; // NOWE
        $nip = isset($_POST['nip']) ? sanitize_text_field(wp_unslash($_POST['nip'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $contact_name = isset($_POST['contact_name']) ? sanitize_text_field(wp_unslash($_POST['contact_name'])) : '';
        $contact_email = isset($_POST['contact_email']) ? sanitize_email(wp_unslash($_POST['contact_email'])) : '';
        // NOWE pola adresowe
        $street = isset($_POST['street']) ? sanitize_text_field(wp_unslash($_POST['street'])) : '';
        $building_number = isset($_POST['building_number']) ? sanitize_text_field(wp_unslash($_POST['building_number'])) : '';
        $postcode = isset($_POST['postcode']) ? sanitize_text_field(wp_unslash($_POST['postcode'])) : '';
        $city = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            wp_die(esc_html__('Nazwa jest wymagana.', 'crm-omd-time-manager'));
        }

        $data = [
            'name'            => $name,
            'full_name'       => $full_name,       // NOWE
            'nip'             => $nip,
            'phone'           => $phone,
            'contact_name'    => $contact_name,
            'contact_email'   => $contact_email,
            'street'          => $street,          // NOWE
            'building_number' => $building_number, // NOWE
            'postcode'        => $postcode,        // NOWE
            'city'            => $city,            // NOWE
            'is_active'       => $is_active,
        ];

        // Typy danych dla wpdb->update/insert:
        // name, full_name, nip, phone, contact_name, contact_email, street, building_number, postcode, city - jako stringi (%s)
        // is_active - jako integer (%d)
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'];

        if ($id > 0) {
            $this->wpdb->update(
                $this->tbl_clients,
                $data,
                ['id' => $id],
                $formats,
                ['%d']
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $formats[] = '%s'; // dla created_at
            $this->wpdb->insert(
                $this->tbl_clients,
                $data,
                $formats
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-clients'));
        exit;
    }

    public function handle_delete_client(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_client_' . $id);

        $this->wpdb->update($this->tbl_clients, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-clients'));
        exit;
    }

    public function render_projects_page(): void
    {
        $this->require_admin_access();

        // Pobieramy klientów i dla każdego projekty (grupowanie)
        $clients = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $projects_by_client = [];
        foreach ($clients as $client) {
            $projects = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, name, is_active, budget, project_status FROM {$this->tbl_projects} WHERE client_id = %d ORDER BY name ASC",
                $client->id
            ));
            if (!empty($projects)) {
                $projects_by_client[] = [
                    'client_name' => $client->name,
                    'projects'    => $projects,
                ];
            }
        }

        $edit_id = isset($_GET['edit_project']) ? (int) $_GET['edit_project'] : 0;
        $edit = $edit_id ? $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tbl_projects} WHERE id = %d", $edit_id)) : null;

        echo '<div class="wrap">';
        echo '<h1>Projekty</h1>';

        // Formularz
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>' . ($edit ? 'Edytuj projekt' : 'Dodaj projekt') . '</h2>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_save_project'); ?>
            <input type="hidden" name="action" value="crm_omd_save_project">
            <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">

            <div class="crm-omd-form-grid">
                <div class="form-field">
                    <label for="client_id">Klient *</label>
                    <select name="client_id" id="client_id" required>
                        <option value="">Wybierz klienta</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int) $client->id; ?>" <?php selected($edit ? (int) $edit->client_id : 0, (int) $client->id); ?>>
                                <?php echo esc_html($client->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label for="name">Nazwa projektu *</label>
                    <input type="text" name="name" id="name" value="<?php echo $edit ? esc_attr($edit->name) : ''; ?>" required>
                </div>
                <!-- NOWE POLE: Budżet -->
                <div class="form-field">
                    <label for="budget">Budżet projektu (PLN)</label>
                    <input type="number" name="budget" id="budget" step="0.01" min="0" value="<?php echo $edit ? esc_attr((string) $edit->budget) : ''; ?>">
                </div>
                <div class="form-field">
                    <label for="project_status">Status projektu</label>
                    <select name="project_status" id="project_status">
                        <?php foreach ($this->get_project_statuses() as $status_key => $status_label): ?>
                            <option value="<?php echo esc_attr($status_key); ?>" <?php selected($edit ? (string) $edit->project_status : self::PROJECT_STATUS_IN_PROGRESS, $status_key); ?>><?php echo esc_html($status_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field" style="display: flex; align-items: center;background: #d0a46c;border-color: #b58b54;color: #212123;padding: 12px 10px 10px 10px;margin-top: 24px">
                    <label style="margin-right: 10px;">
                        <input type="checkbox" name="is_active" value="1" <?php checked($edit ? (int) $edit->is_active : 1, 1); ?>>
                        Aktywny
                    </label>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Zapisz zmiany' : 'Dodaj projekt'; ?></button>
                <?php if ($edit): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crm-omd-projects')); ?>" class="button">Anuluj</a>
                <?php endif; ?>
            </p>
        </form>
        <?php
        echo '</div>';

        // Lista projektów
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>Lista projektów</h2>';
        echo '<table class="widefat striped crm-omd-table">';
        echo '<thead><tr><th>Klient</th><th>Projekt</th><th>Status projektu</th><th>Budżet</th><th>Zaraportowane godziny</th><th>Koszty projektu</th><th>Wynik</th><th>Status</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($projects_by_client as $group) {
            echo '<tr class="client-group-header"><td colspan="9"><strong>' . esc_html($group['client_name']) . '</strong></td></tr>';
            foreach ($group['projects'] as $project) {
                $reported_hours = (float) $this->wpdb->get_var($this->wpdb->prepare("SELECT COALESCE(SUM(hours), 0) FROM {$this->tbl_entries} WHERE project_id = %d", (int) $project->id));
                $project_costs = (float) $this->wpdb->get_var($this->wpdb->prepare("SELECT COALESCE(SUM(cost_value), 0) FROM {$this->tbl_project_costs} WHERE project_id = %d", (int) $project->id));
                $project_result = (float) $project->budget - $reported_hours - $project_costs;

                echo '<tr>';
                echo '<td>' . esc_html($group['client_name']) . '</td>';
                echo '<td>' . esc_html($project->name) . '</td>';
                echo '<td>' . esc_html($this->get_project_status_label((string) $project->project_status)) . '</td>';
                echo '<td>' . esc_html(number_format((float) $project->budget, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format($reported_hours, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format($project_costs, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format($project_result, 2, ',', ' ')) . '</td>';
                echo '<td>' . ((int) $project->is_active ? 'Aktywny' : 'Nieaktywny') . '</td>';
                echo '<td>';
                echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-projects', 'edit_project' => (int) $project->id], admin_url('admin.php'))) . '">Edytuj</a> ';
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_project&id=' . (int) $project->id), 'crm_omd_delete_project_' . (int) $project->id)) . '" onclick="return confirm(\'Usunięcie projektu spowoduje również usunięcie wszystkich powiązanych wpisów godzinowych. Kontynuować?\');">Usuń trwale</a>';
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    public function handle_save_project(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_project');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $budget = isset($_POST['budget']) ? (float) $_POST['budget'] : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $project_status = isset($_POST['project_status']) ? sanitize_text_field(wp_unslash($_POST['project_status'])) : self::PROJECT_STATUS_IN_PROGRESS;
        if (!array_key_exists($project_status, $this->get_project_statuses())) {
            $project_status = self::PROJECT_STATUS_IN_PROGRESS;
        }
        if (!$client_id || $name === '') {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        if ($id > 0) {
            $this->wpdb->update(
                $this->tbl_projects,
                [
                    'client_id' => $client_id,
                    'name'      => $name,
                    'budget'    => $budget,
                    'is_active' => $is_active,
                    'project_status' => $project_status
                ],
                ['id' => $id],
                ['%d', '%s', '%f', '%d', '%s'],
                ['%d']
            );
        } else {
            $this->wpdb->insert(
                $this->tbl_projects,
                [
                    'client_id'   => $client_id,
                    'name'        => $name,
                    'budget'      => $budget,
                    'description' => '',
                    'is_active'   => 1,
                    'project_status' => $project_status,
                    'created_at'  => current_time('mysql')
                ],
                ['%d', '%s', '%f', '%s', '%d', '%s', '%s']
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-projects'));
        exit;
    }

    public function handle_delete_project(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_project_' . $id);

        // Najpierw usuń wpisy powiązane z projektem
        $this->wpdb->delete($this->tbl_entries, ['project_id' => $id], ['%d']);
        // Potem usuń projekt
        $this->wpdb->delete($this->tbl_projects, ['id' => $id], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-projects'));
        exit;
    }

    public function render_services_page(): void
    {
        $this->require_admin_access();

        // Pobieramy klientów i dla każdego usługi (grupowanie)
        $clients = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $services_by_client = [];
        foreach ($clients as $client) {
            $services = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, name, billing_type, hourly_rate, fixed_value, is_active FROM {$this->tbl_services} WHERE client_id = %d ORDER BY name ASC",
                $client->id
            ));
            if (!empty($services)) {
                $services_by_client[] = [
                    'client_name' => $client->name,
                    'services'    => $services,
                ];
            }
        }

        $edit_id = isset($_GET['edit_service']) ? (int) $_GET['edit_service'] : 0;
        $edit = $edit_id ? $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tbl_services} WHERE id = %d", $edit_id)) : null;

        echo '<div class="wrap">';
        echo '<h1>Usługi i stawki</h1>';

        // Formularz
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>' . ($edit ? 'Edytuj usługę' : 'Dodaj usługę') . '</h2>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_save_service'); ?>
            <input type="hidden" name="action" value="crm_omd_save_service">
            <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">

            <div class="crm-omd-form-grid">
                <div class="form-field">
                    <label for="client_id">Klient *</label>
                    <select name="client_id" id="client_id" required>
                        <option value="">Wybierz klienta</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int) $client->id; ?>" <?php selected($edit ? (int) $edit->client_id : 0, (int) $client->id); ?>>
                                <?php echo esc_html($client->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label for="name">Nazwa usługi *</label>
                    <input type="text" name="name" id="name" value="<?php echo $edit ? esc_attr($edit->name) : ''; ?>" required>
                </div>
                <div class="form-field">
                    <label for="billing_type">Typ rozliczenia</label>
                    <select name="billing_type" id="billing_type">
                        <option value="hourly" <?php selected($edit ? $edit->billing_type : 'hourly', 'hourly'); ?>>Godzinowa</option>
                        <option value="fixed" <?php selected($edit ? $edit->billing_type : 'hourly', 'fixed'); ?>>Ryczałt</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="hourly_rate">Stawka godzinowa</label>
                    <input type="number" name="hourly_rate" id="hourly_rate" step="0.01" min="0" value="<?php echo $edit ? esc_attr((string) $edit->hourly_rate) : ''; ?>">
                </div>
                <div class="form-field">
                    <label for="fixed_value">Wartość ryczałtu</label>
                    <input type="number" name="fixed_value" id="fixed_value" step="0.01" min="0" value="<?php echo $edit ? esc_attr((string) $edit->fixed_value) : ''; ?>">
                </div>
                <div class="form-field" style="display: flex; align-items: center;background: #d0a46c;border-color: #b58b54;color: #212123; padding: 12px 10px 10px 10px; margin-top: 24px">
                    <label style="margin-right: 10px;">
                        <input type="checkbox" name="is_active" value="1" <?php checked($edit ? (int) $edit->is_active : 1, 1); ?>>
                        Aktywna
                    </label>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Zapisz zmiany' : 'Dodaj usługę'; ?></button>
                <?php if ($edit): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crm-omd-services')); ?>" class="button">Anuluj</a>
                <?php endif; ?>
            </p>
        </form>
        <?php
        echo '</div>';

        // Lista usług
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>Lista usług</h2>';
        echo '<table class="widefat striped crm-omd-table">';
        echo '<thead><tr><th>Klient</th><th>Usługa</th><th>Typ</th><th>Stawka h</th><th>Ryczałt</th><th>Status</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($services_by_client as $group) {
            echo '<tr class="client-group-header"><td colspan="7"><strong>' . esc_html($group['client_name']) . '</strong></td></tr>';
            foreach ($group['services'] as $service) {
                echo '<tr>';
                echo '<td>' . esc_html($group['client_name']) . '</td>';
                echo '<td>' . esc_html($service->name) . '</td>';
                echo '<td>' . esc_html($service->billing_type) . '</td>';
                echo '<td>' . esc_html(number_format((float) $service->hourly_rate, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format((float) $service->fixed_value, 2, ',', ' ')) . '</td>';
                echo '<td>' . ((int) $service->is_active ? 'Aktywna' : 'Nieaktywna') . '</td>';
                echo '<td>';
                echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-services', 'edit_service' => (int) $service->id], admin_url('admin.php'))) . '">Edytuj</a> ';
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_service&id=' . (int) $service->id), 'crm_omd_delete_service_' . (int) $service->id)) . '">Dezaktywuj</a>';
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    public function handle_save_service(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_service');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $billing_type = isset($_POST['billing_type']) ? sanitize_text_field(wp_unslash($_POST['billing_type'])) : 'hourly';
        $hourly_rate = isset($_POST['hourly_rate']) ? (float) $_POST['hourly_rate'] : 0;
        $fixed_value = isset($_POST['fixed_value']) ? (float) $_POST['fixed_value'] : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if (!$client_id || $name === '' || !in_array($billing_type, ['hourly', 'fixed'], true)) {
            wp_die(esc_html__('Wypełnij poprawnie formularz.', 'crm-omd-time-manager'));
        }

        $data = [
            'client_id' => $client_id,
            'name' => $name,
            'billing_type' => $billing_type,
            'hourly_rate' => $hourly_rate,
            'fixed_value' => $fixed_value,
            'is_active' => $is_active,
        ];
        if ($id > 0) {
            $this->wpdb->update($this->tbl_services, $data, ['id' => $id], ['%d', '%s', '%s', '%f', '%f', '%d'], ['%d']);
        } else {
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($this->tbl_services, $data, ['%d', '%s', '%s', '%f', '%f', '%d', '%s']);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-services'));
        exit;
    }

    public function handle_delete_service(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_service_' . $id);
        $this->wpdb->update($this->tbl_services, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);
        wp_safe_redirect(admin_url('admin.php?page=crm-omd-services'));
        exit;
    }

    public function render_workers_page(): void
    {
        $this->require_admin_access();
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);

        $edit_worker_id = isset($_GET['edit_worker']) ? (int) $_GET['edit_worker'] : 0;
        $edit_worker = $edit_worker_id > 0 ? get_user_by('id', $edit_worker_id) : false;

        echo '<div class="wrap">';
        echo '<h1>Pracownicy</h1>';

        // Karta ustawień przypomnień
        $reminder_mode = (string) get_option('crm_omd_reminder_mode', 'interval');
        $reminder_interval_days = max(1, (int) get_option('crm_omd_reminder_interval_days', 5));
        $reminder_day_of_month = min(31, max(1, (int) get_option('crm_omd_reminder_day_of_month', 5))); // zakres 1-31

        echo '<div class="crm-omd-admin-card">';
        echo '<h2>Ustawienia przypomnień</h2>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_save_reminder_settings'); ?>
            <input type="hidden" name="action" value="crm_omd_save_reminder_settings">

            <div class="crm-omd-form-grid">
                <div class="form-field">
                    <label for="reminder_mode">Tryb przypomnienia</label>
                    <select name="reminder_mode" id="reminder_mode">
                        <option value="interval" <?php selected($reminder_mode, 'interval'); ?>>Co X dni</option>
                        <option value="monthly" <?php selected($reminder_mode, 'monthly'); ?>>Konkretny dzień miesiąca</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="reminder_interval_days">Interwał dni (dla trybu "Co X dni")</label>
                    <input type="number" name="reminder_interval_days" id="reminder_interval_days" min="1" max="60" value="<?php echo esc_attr((string) $reminder_interval_days); ?>">
                </div>
                <div class="form-field">
                    <label for="reminder_day_of_month">Dzień miesiąca (dla trybu miesięcznego, 1-31)</label>
                    <input type="number" name="reminder_day_of_month" id="reminder_day_of_month" min="1" max="31" value="<?php echo esc_attr((string) $reminder_day_of_month); ?>">
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary">Zapisz ustawienia przypomnień</button>
            </p>
        </form>
        <?php
        echo '</div>';

        // Karta edycji konta (jeśli wybrano)
        if ($edit_worker instanceof WP_User) {
            echo '<div class="crm-omd-admin-card">';
            echo '<h2>Edycja konta: ' . esc_html($edit_worker->display_name) . '</h2>';
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('crm_omd_update_worker_' . (int) $edit_worker->ID); ?>
                <input type="hidden" name="action" value="crm_omd_update_worker">
                <input type="hidden" name="user_id" value="<?php echo (int) $edit_worker->ID; ?>">

                <div class="crm-omd-form-grid">
                    <div class="form-field">
                        <label for="new_password">Nowe hasło (opcjonalnie)</label>
                        <input type="password" name="new_password" id="new_password" autocomplete="new-password">
                    </div>
                    <div class="form-field">
                        <label for="role">Rola</label>
                        <select name="role" id="role">
                            <?php foreach (array_keys(get_editable_roles()) as $role_key): ?>
                                <option value="<?php echo esc_attr($role_key); ?>" <?php selected(in_array($role_key, $edit_worker->roles, true), true); ?>>
                                    <?php echo esc_html($role_key); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">Zapisz konto</button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_worker&user_id=' . (int) $edit_worker->ID), 'crm_omd_delete_worker_' . (int) $edit_worker->ID)); ?>" onclick="return confirm('Usunąć konto pracownika?');">Usuń konto</a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crm-omd-workers')); ?>" class="button">Anuluj</a>
                </p>
            </form>
            <?php
            echo '</div>';
        }

        // Pobierz zbiorcze podsumowanie dla wszystkich użytkowników
        $admin_month = isset($_GET['worker_month']) ? sanitize_text_field(wp_unslash($_GET['worker_month'])) : date('Y-m');
        $admin_month = preg_match('/^\d{4}-\d{2}$/', $admin_month) ? $admin_month : date('Y-m');
        [$admin_date_from, $admin_date_to] = $this->get_month_boundaries($admin_month);
        $admin_year = (int) substr($admin_month, 0, 4);
        $admin_month_num = (int) substr($admin_month, 5, 2);
        $admin_expected_hours = $this->get_working_days_in_month($admin_year, $admin_month_num) * 8;

        $summary = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT user_id,
                   SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END) AS total_hours,
                   SUM(CASE WHEN status = 'approved_off' THEN hours ELSE 0 END) AS total_off_hours,
                   SUM(CASE WHEN status = 'approved' THEN calculated_value ELSE 0 END) AS total_revenue
            FROM {$this->tbl_entries}
            WHERE work_date BETWEEN %s AND %s
              AND status IN ('approved', 'approved_off')
            GROUP BY user_id
        ", $admin_date_from, $admin_date_to), OBJECT_K);

        // Karta ustawień pracowników
        echo '<div class="crm-omd-admin-card crm-omd-workers-settings">';
        echo '<h2>Ustawienia pracowników</h2>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_save_worker_settings'); ?>
            <input type="hidden" name="action" value="crm_omd_save_worker_settings">

            <table class="widefat striped crm-omd-table">
                <thead>
                    <tr>
                        <th>Użytkownik</th>
                        <th>Email</th>
                        <th>Rola</th>
                        <th>Ostatnie logowanie</th>
                        <th>Aktywny</th>
                        <th>Przypomnienia</th>
                        <th>Pensja miesięczna</th>
                        <th>Stawka godzinowa</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user):
                        $enabled = get_user_meta($user->ID, 'crm_omd_worker_enabled', true);
                        if ($enabled === '') {
                            $enabled = '1';
                        }
                        $reminder = get_user_meta($user->ID, 'crm_omd_worker_reminder', true);
                        if ($reminder === '') {
                            $reminder = '1';
                        }
                        $last_login = get_user_meta($user->ID, 'crm_omd_last_login', true);
                        $monthly_salary = (float) get_user_meta($user->ID, 'crm_omd_worker_monthly_salary', true);
                        $hourly_rate = (float) get_user_meta($user->ID, 'crm_omd_worker_hourly_rate', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($user->display_name); ?></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html(implode(', ', $user->roles)); ?></td>
                        <td><?php echo esc_html($last_login ?: 'brak'); ?></td>
                        <td>
                            <input type="hidden" name="worker_enabled[<?php echo (int) $user->ID; ?>]" value="0">
                            <label>
                                <input type="checkbox" name="worker_enabled[<?php echo (int) $user->ID; ?>]" value="1" <?php checked($enabled, '1'); ?>>
                                Tak
                            </label>
                        </td>
                        <td>
                            <input type="hidden" name="worker_reminder[<?php echo (int) $user->ID; ?>]" value="0">
                            <label>
                                <input type="checkbox" name="worker_reminder[<?php echo (int) $user->ID; ?>]" value="1" <?php checked($reminder, '1'); ?>>
                                Tak
                            </label>
                        </td>
                        <td>
                            <input type="number" name="worker_monthly_salary[<?php echo (int) $user->ID; ?>]" min="0" step="0.01" value="<?php echo esc_attr(number_format($monthly_salary, 2, '.', '')); ?>" style="width:120px;">
                        </td>
                        <td>
                            <input type="number" name="worker_hourly_rate[<?php echo (int) $user->ID; ?>]" min="0" step="0.01" value="<?php echo esc_attr(number_format($hourly_rate, 2, '.', '')); ?>" style="width:100px;">
                        </td>
                        <td>
                            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'crm-omd-workers', 'edit_worker' => (int) $user->ID], admin_url('admin.php'))); ?>">Edytuj konto</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Zapisz ustawienia pracowników</button>
            </p>
        </form>
        <?php
        echo '</div>';

        
       
        // Karta podsumowania z kosztem i zyskiem
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>Podsumowanie pracowników</h2>';
        ?>
        <form method="get" style="margin-bottom: 15px;">
            <input type="hidden" name="page" value="crm-omd-workers">
            <label>Miesiąc:
                <select name="worker_month">
                    <?php echo $this->get_month_options($admin_month); ?>
                </select>
            </label>
            <button type="submit" class="button">Pokaż</button>
        </form>

        <table class="widefat striped crm-omd-table crm-omd-summary-table">
            <thead>
                <tr>
                    <th>Pracownik</th>
                    <th>Zaraportowane godziny</th>
                    <th>Zaakceptowane OFF</th>
                    <th>Godziny do przepracowania</th>
                    <th>Różnica godzin</th>
                    <th>Wypracowany zysk (PLN)</th>
                    <th>Pensja (PLN)</th>
                    <th>Koszt (PLN)</th>
                    <th>Zysk netto (PLN)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user):
                    $reported = isset($summary[$user->ID]) ? (float) $summary[$user->ID]->total_hours : 0;
                    $approved_off = isset($summary[$user->ID]) ? (float) $summary[$user->ID]->total_off_hours : 0;
                    $revenue  = isset($summary[$user->ID]) ? (float) $summary[$user->ID]->total_revenue : 0;
                    $salary = (float) get_user_meta($user->ID, 'crm_omd_worker_monthly_salary', true);
                    $hourly_rate = (float) get_user_meta($user->ID, 'crm_omd_worker_hourly_rate', true);
                    $cost = $reported * $hourly_rate; // uproszczenie – tylko godziny
                    $profit_net = $revenue - $cost;
                    
                    // Stałe godziny do przepracowania (pełny etat)
                    $hours_to_work = $admin_expected_hours;
                    // Różnica = godziny do przepracowania - zaraportowane - OFF
                    $difference = $admin_expected_hours - $reported - $approved_off;
                ?>
                <tr>
                    <td><?php echo esc_html($user->display_name); ?></td>
                    <td><?php echo esc_html(number_format($reported, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($approved_off, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($hours_to_work, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($difference, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($revenue, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($salary, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($cost, 2, ',', ' ')); ?></td>
                    <td><?php echo esc_html(number_format($profit_net, 2, ',', ' ')); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo '</div>';
        echo '</div>';
    }

    public function handle_save_worker_settings(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_worker_settings');

        $users = get_users(['fields' => 'ID']);
        $enabled = isset($_POST['worker_enabled']) && is_array($_POST['worker_enabled']) ? $_POST['worker_enabled'] : [];
        $reminder = isset($_POST['worker_reminder']) && is_array($_POST['worker_reminder']) ? $_POST['worker_reminder'] : [];
        $monthly_salary = isset($_POST['worker_monthly_salary']) && is_array($_POST['worker_monthly_salary']) ? $_POST['worker_monthly_salary'] : [];
        $hourly_rates = isset($_POST['worker_hourly_rate']) && is_array($_POST['worker_hourly_rate']) ? $_POST['worker_hourly_rate'] : [];

        foreach ($users as $id) {
            $is_enabled = isset($enabled[$id]) && $enabled[$id] === '1' ? '1' : '0';
            $is_reminder = isset($reminder[$id]) && $reminder[$id] === '1' ? '1' : '0';
            $salary_raw = isset($monthly_salary[$id]) ? (string) wp_unslash($monthly_salary[$id]) : '0';
            $salary = max(0, (float) str_replace(',', '.', $salary_raw));
            $hourly_raw = isset($hourly_rates[$id]) ? (string) wp_unslash($hourly_rates[$id]) : '0';
            $hourly = max(0, (float) str_replace(',', '.', $hourly_raw));
            
            update_user_meta($id, 'crm_omd_worker_enabled', $is_enabled);
            update_user_meta($id, 'crm_omd_worker_reminder', $is_reminder);
            update_user_meta($id, 'crm_omd_worker_monthly_salary', $salary);
            update_user_meta($id, 'crm_omd_worker_hourly_rate', $hourly);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function handle_save_reminder_settings(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_reminder_settings');

        $mode = isset($_POST['reminder_mode']) ? sanitize_text_field(wp_unslash($_POST['reminder_mode'])) : 'interval';
        if (!in_array($mode, ['interval', 'monthly'], true)) {
            $mode = 'interval';
        }

        $interval = isset($_POST['reminder_interval_days']) ? (int) $_POST['reminder_interval_days'] : 5;
        $interval = max(1, min(60, $interval));

        $day_of_month = isset($_POST['reminder_day_of_month']) ? (int) $_POST['reminder_day_of_month'] : 5;
        $day_of_month = min(31, max(1, $day_of_month)); // zakres 1-31

        update_option('crm_omd_reminder_mode', $mode);
        update_option('crm_omd_reminder_interval_days', $interval);
        update_option('crm_omd_reminder_day_of_month', $day_of_month);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function handle_update_worker(): void
    {
        $this->require_admin_access();
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        check_admin_referer('crm_omd_update_worker_' . $user_id);

        if ($user_id <= 0) {
            wp_die(esc_html__('Niepoprawny użytkownik.', 'crm-omd-time-manager'));
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_die(esc_html__('Użytkownik nie istnieje.', 'crm-omd-time-manager'));
        }

        $role = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : '';
        $new_password = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';

        if ($role !== '' && array_key_exists($role, get_editable_roles())) {
            $user->set_role($role);
        }

        if ($new_password !== '') {
            wp_set_password($new_password, $user_id);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function handle_delete_worker(): void
    {
        $this->require_admin_access();
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        check_admin_referer('crm_omd_delete_worker_' . $user_id);

        if ($user_id <= 0 || $user_id === get_current_user_id()) {
            wp_die(esc_html__('Nie można usunąć tego konta.', 'crm-omd-time-manager'));
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function render_reports_page(): void
    {
        $this->require_admin_access();

        // Pobieranie parametrów
        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : date('Y-m');
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : ($month . '-01');
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : date('Y-m-t', strtotime($month . '-01'));
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        $project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
        $detail = isset($_GET['detail']) ? 1 : 0;
        
        // Sortowanie
        $order_by = isset($_GET['order_by']) ? sanitize_text_field(wp_unslash($_GET['order_by'])) : ($detail ? 'work_date' : 'client_name');
        $order_dir = isset($_GET['order_dir']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['order_dir']))) : 'ASC';
        
        // Dozwolone kolumny do sortowania
        if ($detail) {
            $allowed_order = ['work_date', 'client_name', 'project_name', 'service_name', 'display_name', 'hours', 'calculated_value'];
            $order_column = $order_by;
            if (!in_array($order_column, $allowed_order, true)) {
                $order_column = 'work_date';
            }
            $order_map = [
                'work_date' => 'e.work_date',
                'client_name' => 'c.name',
                'project_name' => 'p.name',
                'service_name' => 's.name',
                'display_name' => 'u.display_name',
                'hours' => 'e.hours',
                'calculated_value' => 'e.calculated_value',
            ];
            $sql_order_by = $order_map[$order_column] ?? 'e.work_date';
        } else {
            $allowed_order = ['client_name', 'hours_sum', 'value_sum'];
            $order_column = $order_by;
            if (!in_array($order_column, $allowed_order, true)) {
                $order_column = 'client_name';
            }
            $order_map = [
                'client_name' => 'client_name',
                'hours_sum' => 'hours_sum',
                'value_sum' => 'value_sum',
            ];
            $sql_order_by = $order_map[$order_column] ?? 'client_name';
        }
        $sql_order_dir = ($order_dir === 'DESC') ? 'DESC' : 'ASC';

        $clients = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $projects = $this->wpdb->get_results("SELECT id, name FROM {$this->tbl_projects} WHERE is_active = 1 ORDER BY name ASC");

        $where = "WHERE e.status = 'approved' AND e.work_date BETWEEN %s AND %s";
        $params = [$date_from, $date_to];
        if ($client_id) {
            $where .= ' AND e.client_id = %d';
            $params[] = $client_id;
        }
        if ($project_id) {
            $where .= ' AND e.project_id = %d';
            $params[] = $project_id;
        }

        if ($detail) {
            // Dodajemy e.user_id, aby móc pobrać stawkę pracownika
            $sql = "SELECT e.user_id, e.work_date, c.name AS client_name, p.name AS project_name, s.name AS service_name, u.display_name, e.hours, e.calculated_value, e.description
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                    INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
                    INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                    INNER JOIN {$this->wpdb->users} u ON u.ID = e.user_id
                    {$where}
                    ORDER BY {$sql_order_by} {$sql_order_dir}, e.id ASC";
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params));

            // Pobranie stawek godzinowych dla wszystkich występujących użytkowników
            $user_ids = array_unique(array_column($rows, 'user_id'));
            $hourly_rates = [];
            foreach ($user_ids as $uid) {
                $hourly_rates[$uid] = (float) get_user_meta($uid, 'crm_omd_worker_hourly_rate', true);
            }
        } else {
            $sql = "SELECT c.name AS client_name, SUM(e.hours) AS hours_sum, SUM(e.calculated_value) AS value_sum
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                    {$where}
                    GROUP BY e.client_id
                    ORDER BY {$sql_order_by} {$sql_order_dir}";
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params));
        }

        echo '<div class="wrap">';
        echo '<h1>Raporty</h1>';

        // Panel filtrów
        echo '<div class="crm-omd-filters-bar">';
        echo '<form method="get" style="display: contents;" id="crm-omd-report-form">';
        echo '<input type="hidden" name="page" value="crm-omd-reports">';
        echo '<label>Miesiąc: <select name="month" id="report-month">';
        echo $this->get_month_options($month);
        echo '</select></label>';
        echo '<label>Od: <input type="date" name="date_from" id="report-date-from" value="' . esc_attr($date_from) . '"></label>';
        echo '<label>Do: <input type="date" name="date_to" id="report-date-to" value="' . esc_attr($date_to) . '"></label>';
        echo '<label>Klient: <select name="client_id">';
        echo '<option value="0">Wszyscy</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '"' . selected($client_id, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Projekt: <select name="project_id">';
        echo '<option value="0">Wszystkie</option>';
        foreach ($projects as $project) {
            echo '<option value="' . (int) $project->id . '"' . selected($project_id, (int) $project->id, false) . '>' . esc_html($project->name) . '</option>';
        }
        echo '</select></label>';
        echo '<label><input type="checkbox" name="detail" value="1"  style="min-width: 16px;"' . checked($detail, 1, false) . '> Szczegółowy</label>';
        // Zachowaj sortowanie przy zmianie filtrów
        echo '<input type="hidden" name="order_by" value="' . esc_attr($order_by) . '">';
        echo '<input type="hidden" name="order_dir" value="' . esc_attr($order_dir) . '">';
        echo '<button type="submit" class="button button-primary">Generuj</button>';
        echo '</form>';
        echo '</div>';

        // Przycisk eksportu
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom: 20px;">';
        wp_nonce_field('crm_omd_export_report');
        echo '<input type="hidden" name="action" value="crm_omd_export_report">';
        echo '<input type="hidden" name="month" value="' . esc_attr($month) . '">';
        echo '<input type="hidden" name="date_from" value="' . esc_attr($date_from) . '">';
        echo '<input type="hidden" name="date_to" value="' . esc_attr($date_to) . '">';
        echo '<input type="hidden" name="client_id" value="' . (int) $client_id . '">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="detail" value="' . (int) $detail . '">';
        echo '<button type="submit" class="button">Eksport do Excel (CSV)</button>';
        echo '</form>';

        // Tabela raportu
        echo '<div class="crm-omd-admin-card">';
        echo '<h2>Wyniki</h2>';
        echo '<table class="widefat striped crm-omd-table">';
        echo '<thead><tr>';

        if ($detail) {
            $base_url = remove_query_arg(['order_by', 'order_dir']);
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'work_date', 'order_dir' => ($order_by === 'work_date' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Data</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'client_name', 'order_dir' => ($order_by === 'client_name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Klient</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'project_name', 'order_dir' => ($order_by === 'project_name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Projekt</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'service_name', 'order_dir' => ($order_by === 'service_name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Usługa</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'display_name', 'order_dir' => ($order_by === 'display_name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Pracownik</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'hours', 'order_dir' => ($order_by === 'hours' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Godziny</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'calculated_value', 'order_dir' => ($order_by === 'calculated_value' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Przychód</a></th>';
            echo '<th>Koszt</th><th>Zysk</th>';
            echo '<th>Opis</th>';
        } else {
            $base_url = remove_query_arg(['order_by', 'order_dir']);
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'client_name', 'order_dir' => ($order_by === 'client_name' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Klient</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'hours_sum', 'order_dir' => ($order_by === 'hours_sum' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Ilość godzin (miesiąc)</a></th>';
            echo '<th><a href="' . esc_url(add_query_arg(['order_by' => 'value_sum', 'order_dir' => ($order_by === 'value_sum' && $order_dir === 'ASC' ? 'DESC' : 'ASC')], $base_url)) . '">Łączna kwota</a></th>';
        }
        echo '</tr></thead><tbody>';

        $total_hours = 0.0;
        $total_value = 0.0;
        $total_cost = 0.0;
        $total_profit = 0.0;

        foreach ($rows as $row) {
            echo '<tr>';
            if ($detail) {
                $hourly_rate = isset($hourly_rates[$row->user_id]) ? $hourly_rates[$row->user_id] : 0;
                // Uproszczenie: koszt liczony tylko dla wpisów z godzinami > 0 (zakładamy, że to wpisy godzinowe)
                $cost = ($row->hours > 0) ? $row->hours * $hourly_rate : 0;
                $profit = $row->calculated_value - $cost;

                echo '<td>' . esc_html($row->work_date) . '</td>';
                echo '<td>' . esc_html($row->client_name) . '</td>';
                echo '<td>' . esc_html($row->project_name) . '</td>';
                echo '<td>' . esc_html($row->service_name) . '</td>';
                echo '<td>' . esc_html($row->display_name) . '</td>';
                echo '<td>' . esc_html((string) $row->hours) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row->calculated_value, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format($cost, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html(number_format($profit, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html($row->description) . '</td>';

                $total_hours += (float) $row->hours;
                $total_value += (float) $row->calculated_value;
                $total_cost += $cost;
                $total_profit += $profit;
            } else {
                echo '<td>' . esc_html($row->client_name) . '</td>';
                echo '<td>' . esc_html((string) $row->hours_sum) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row->value_sum, 2, ',', ' ')) . '</td>';
                $total_hours += (float) $row->hours_sum;
                $total_value += (float) $row->value_sum;
            }
            echo '</tr>';
        }

        echo '</tbody><tfoot><tr>';
        if ($detail) {
            echo '<th colspan="6">SUMA</th>';
            echo '<th>' . esc_html(number_format($total_value, 2, ',', ' ')) . '</th>';
            echo '<th>' . esc_html(number_format($total_cost, 2, ',', ' ')) . '</th>';
            echo '<th>' . esc_html(number_format($total_profit, 2, ',', ' ')) . '</th>';
            echo '<th></th>';
        } else {
            echo '<th>SUMA</th>';
            echo '<th>' . esc_html(number_format($total_hours, 2, ',', ' ')) . '</th>';
            echo '<th>' . esc_html(number_format($total_value, 2, ',', ' ')) . '</th>';
        }
        echo '</tr></tfoot></table>';
        echo '</div>';
        echo '</div>';

        // JavaScript do obsługi zmiany miesiąca
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#report-month').on('change', function() {
                var month = $(this).val();
                if (month) {
                    var firstDay = month + '-01';
                    var lastDay = new Date(month + '-01');
                    lastDay.setMonth(lastDay.getMonth() + 1);
                    lastDay.setDate(lastDay.getDate() - 1);
                    var lastDayStr = lastDay.toISOString().split('T')[0];
                    $('#report-date-from').val(firstDay);
                    $('#report-date-to').val(lastDayStr);
                }
            });
        });
        </script>
        <?php
    }

    public function handle_export_report(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_export_report');

        $month = isset($_POST['month']) ? sanitize_text_field(wp_unslash($_POST['month'])) : date('Y-m');
        $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : ($month . '-01');
        $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : date('Y-m-t', strtotime($month . '-01'));
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $detail = isset($_POST['detail']) ? (int) $_POST['detail'] : 0;

        $where = "WHERE e.status = 'approved' AND e.work_date BETWEEN %s AND %s";
        $params = [$date_from, $date_to];
        if ($client_id) {
            $where .= ' AND e.client_id = %d';
            $params[] = $client_id;
        }
        if ($project_id) {
            $where .= ' AND e.project_id = %d';
            $params[] = $project_id;
        }

        if ($detail) {
            $sql = "SELECT e.user_id, e.work_date, c.name AS client_name, p.name AS project_name, s.name AS service_name, u.display_name, e.hours, e.calculated_value, e.description
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                    INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
                    INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                    INNER JOIN {$this->wpdb->users} u ON u.ID = e.user_id
                    {$where}
                    ORDER BY c.name ASC, e.work_date ASC, e.id ASC";
            $detail_rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

            $user_ids = [];
            foreach ($detail_rows as $row) {
                $user_ids[(int) $row['user_id']] = true;
            }
            $hourly_rates = [];
            foreach (array_keys($user_ids) as $uid) {
                $hourly_rates[$uid] = (float) get_user_meta((int) $uid, 'crm_omd_worker_hourly_rate', true);
            }

            $rows = [];
            $current_client = null;
            foreach ($detail_rows as $row) {
                $client_name = (string) $row['client_name'];
                if ($current_client !== $client_name) {
                    if ($current_client !== null) {
                        $rows[] = ['', '', '', '', '', '', '', '', '', ''];
                    }
                    $rows[] = ['Klient', $client_name, '', '', '', '', '', '', '', ''];
                    $rows[] = ['Data', 'Klient', 'Projekt', 'Usługa', 'Pracownik', 'Godziny', 'Przychód', 'Koszt', 'Zysk', 'Opis'];
                    $current_client = $client_name;
                }

                $hours = (float) $row['hours'];
                $revenue = (float) $row['calculated_value'];
                $cost = $hours * ($hourly_rates[(int) $row['user_id']] ?? 0);
                $profit = $revenue - $cost;

                $rows[] = [
                    $row['work_date'],
                    $row['client_name'],
                    $row['project_name'],
                    $row['service_name'],
                    $row['display_name'],
                    number_format($hours, 2, '.', ''),
                    number_format($revenue, 2, '.', ''),
                    number_format($cost, 2, '.', ''),
                    number_format($profit, 2, '.', ''),
                    $row['description'],
                ];
            }
        } else {
            $sql = "SELECT c.name AS client_name, SUM(e.hours) AS godziny, SUM(e.calculated_value) AS kwota
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                    {$where}
                    GROUP BY e.client_id
                    ORDER BY c.name ASC";
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=crm-omd-raport-' . $month . '.csv');
        echo "\xEF\xBB\xBF"; // BOM
        $output = fopen('php://output', 'w');
        if (!$output) {
            exit;
        }
        if (!empty($rows)) {
            if (!$detail) {
                fputcsv($output, array_keys($rows[0]), ';');
            }
            foreach ($rows as $row) {
                fputcsv($output, $row, ';');
            }
        }
        fclose($output);
        exit;
    }

    public function send_daily_reminders(): void
    {
        $today = current_time('Y-m-d');
        $mode = (string) get_option('crm_omd_reminder_mode', 'interval');
        $interval_days = max(1, (int) get_option('crm_omd_reminder_interval_days', 5));
        $day_of_month = min(31, max(1, (int) get_option('crm_omd_reminder_day_of_month', 5))); // zakres 1-31

        if ($mode === 'monthly') {
            if ((int) current_time('j') !== $day_of_month) {
                return;
            }
        } else {
            $last_sent = (string) get_option('crm_omd_last_global_reminder_sent', '1970-01-01');
            if ($last_sent !== '') {
                $last_ts = strtotime($last_sent);
                $today_ts = strtotime($today);
                if ($last_ts && $today_ts && ($today_ts - $last_ts) < ($interval_days * DAY_IN_SECONDS)) {
                    return;
                }
            }
        }

        $users = get_users(['role__in' => ['subscriber', 'author', 'editor', 'administrator', self::ROLE_EMPLOYEE, self::ROLE_MANAGER, self::ROLE_LEGACY_EMPLOYEE, self::ROLE_LEGACY_MANAGER]]);

        foreach ($users as $user) {
            $enabled = get_user_meta($user->ID, 'crm_omd_worker_enabled', true);
            $reminder = get_user_meta($user->ID, 'crm_omd_worker_reminder', true);
            if ($enabled !== '1' || $reminder !== '1') {
                continue;
            }

            $count = (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->tbl_entries} WHERE user_id = %d AND work_date = %s", $user->ID, $today));
            if ($count === 0) {
                wp_mail(
                    $user->user_email,
                    'Przypomnienie o uzupełnieniu godzin',
                    'Cześć, przypominamy o uzupełnieniu czasu pracy w systemie.'
                );
            }
        }

        update_option('crm_omd_last_global_reminder_sent', $today);
    }
}

new CRM_OMD_Time_Manager();
?>
