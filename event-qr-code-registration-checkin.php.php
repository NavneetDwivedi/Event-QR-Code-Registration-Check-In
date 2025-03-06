<?php
/**
 * Plugin Name: Event QR Code Registration & Check-In
 * Description: Easily register event attendees, generate QR codes, and enable contactless check-in.
 * Version: 1.0
 * Author: Navneet Dwivedi
 * Author URI: https://www.linkedin.com/in/navneet-divedi-9013245b/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: event-qr-registration
 * Domain Path: /languages
 */


if (!defined('ABSPATH')) {
    exit;
}

// Create Database Table
function event_qr_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'event_qr_codes';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
		mobile VARCHAR(20) NOT NULL,
        qr_code VARCHAR(255) NOT NULL UNIQUE,
        scanned TINYINT(1) DEFAULT 0
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'event_qr_create_table');

// Generate QR Verification Link
function event_qr_generate_link($qr_code) {
    $page_url = get_permalink(get_page_by_title('QR Code Verification')); 
    return esc_url($page_url . '?verify_qr=' . $qr_code);
}

// QR Verification Page Content (Fixes Double Message Issue)
function event_qr_verification_page() {
    if (isset($_GET['verify_qr'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'event_qr_codes';
        $qr_code = sanitize_text_field($_GET['verify_qr']);
        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE qr_code = %s", $qr_code));

        ob_start();
        echo "<style> 
                body { font-family: Arial, sans-serif; text-align: center; margin-top: 50px; } 
                .message { font-size: 20px; padding: 20px; border-radius: 5px; display: inline-block; margin: 10px 0; } 
                .success { background-color: #28a745; color: white; padding: 10px; } 
                .error { background-color: #dc3545; color: white; padding: 10px; }
              </style>";

        // **Show success message only if check-in just happened**
        if (isset($_GET['checkin_success']) && $_GET['checkin_success'] == 1) {
            echo "<div class='message success'>✅ You have successfully checked in! You cannot scan this QR code again.</div>";
        } 
        // **If QR is already scanned and not just checked in now, show error**
        elseif ($user && $user->scanned == 1) {
            echo "<div class='message error'>❌ This QR code has already been used! Check-in not allowed.</div>";
        }

        if ($user) {
            if ($user->scanned == 0) {
                if (current_user_can('manage_options')) {
                    echo "<div class='message success'>✅ Welcome, " . esc_html($user->name) . "!</div>";
                    echo "<p>Would you like to check in now or later?</p>";
                    echo "<form method='POST' action='" . esc_url(admin_url('admin-post.php')) . "'>
                            <input type='hidden' name='action' value='process_checkin'>
                            <input type='hidden' name='qr_code' value='" . esc_attr($qr_code) . "'>
                            <label><input type='radio' name='check_in' value='now'> Check-In Now</label><br>
                            <label><input type='radio' name='check_in' value='later' checked> Check-In Later</label><br><br>
                            <button type='submit'>Submit</button>
                          </form>";
                } else {
                    echo "<div class='message error'>📍 Please show this QR code at the venue.</div>";
                }
            }
        } else {
            echo "<div class='message error'>❌ Invalid QR Code!</div>";
        }
        return ob_get_clean();
    }
}
add_shortcode('event_qr_verification', 'event_qr_verification_page');
// Handle Check-In Submission

// Handle Check-In Submission (Now Shows Success Message)
function event_qr_process_checkin() {
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === "process_checkin") {
        global $wpdb;
        $table_name = $wpdb->prefix . 'event_qr_codes';
        $qr_code = sanitize_text_field($_POST['qr_code']);
        $check_in_choice = sanitize_text_field($_POST['check_in']);

        if ($check_in_choice === 'now') {
            // Mark as checked-in
            $wpdb->update($table_name, ['scanned' => 1], ['qr_code' => $qr_code]);

            // Redirect with success parameter
            $redirect_url = add_query_arg(['verify_qr' => $qr_code, 'checkin_success' => 1], get_permalink(get_page_by_title('QR Code Verification')));
            wp_redirect($redirect_url);
            exit;
        } else {
            // If "Check-In Later" is selected, simply reload the page
            $redirect_url = add_query_arg(['verify_qr' => $qr_code], get_permalink(get_page_by_title('QR Code Verification')));
            wp_redirect($redirect_url);
            exit;
        }
    }
}
add_action('admin_post_process_checkin', 'event_qr_process_checkin');
add_action('admin_post_nopriv_process_checkin', 'event_qr_process_checkin');



// Handle User Registration via AJAX
function event_qr_ajax_register() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'event_qr_codes';

    $name = sanitize_text_field($_POST['name']);
    $email = sanitize_email($_POST['email']);
	$mobile = sanitize_text_field($_POST['mobile']);
    $qr_code = md5(uniqid($email, true));

    // Check if user already registered
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE email = %s", $email));
    if ($exists) {
        wp_send_json_error("You have already registered!");
    }

    // Save registration
    $wpdb->insert($table_name, [
        'name' => $name,
        'email' => $email,
		'mobile' => $mobile,
        'qr_code' => $qr_code,
    ]);

    // Generate QR Code URL
    $verify_url = event_qr_generate_link($qr_code);
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=1080x1080&data=' . urlencode($verify_url);
    $download_link = site_url("?download_qr=$qr_code");

    // Send Email
    $subject = "Your Event QR Code";
    $message = "Hello $name,\n\nHere is your QR code for the event. Show this at the venue.\n\nQR Code Link: $verify_url\n\nDownload Your QR Code: $qr_url";
    wp_mail($email, $subject, $message);

    wp_send_json_success(['qr_url' => $qr_url, 'download_link' => $qr_url]);
}
add_action('wp_ajax_event_qr_register', 'event_qr_ajax_register');
add_action('wp_ajax_nopriv_event_qr_register', 'event_qr_ajax_register');

// Registration Form Shortcode
function event_qr_registration_form() {
    ob_start();
    ?>
    <form id="event_qr_form">
        <label for="name">Name:</label>
        <input type="text" name="name" id="name" required>
        
        <label for="email">Email:</label>
        <input type="email" name="email" id="email" required>
		
		<label for="mobile">Mobile:</label>
        <input type="text" name="mobile" id="mobile" required>
        
        <button type="submit">Register</button>
    </form>

    <div id="qr_result"></div>

    <script>
        jQuery(document).ready(function($) {
            $('#event_qr_form').on('submit', function(e) {
                e.preventDefault();
                var name = $('#name').val();
                var email = $('#email').val();
				var mobile = $('#mobile').val()

                $.ajax({
                    type: "POST",
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    data: {
                        action: "event_qr_register",
                        name: name,
                        email: email,
						mobile: mobile
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#qr_result').html('<p style="color:green;">Registration successful! Here is your QR code for the event. Show this at the venue.</p>' +
                                '<img src="' + response.data.qr_url + '" alt="QR Code">' +
                                '<br><a href="' + response.data.qr_url + '" download="event_qr.png" target="_blank"><button>Download QR Code</button></a>');
                        } else {
                            $('#qr_result').html('<p style="color:red;">' + response.data + '</p>');
                        }
                    }
                });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('event_qr_form', 'event_qr_registration_form');


// Admin Dashboard to View Registered Users
function event_qr_admin_menu() {
    add_menu_page(
        'Event QR Registrations',
        'QR Registrations',
        'manage_options',
        'event-qr-registrations',
        'event_qr_admin_page',
        'dashicons-tickets',
        20
    );
}
add_action('admin_menu', 'event_qr_admin_menu');

// Admin Dashboard to View and Delete Registered Users
function event_qr_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'event_qr_codes';

    // Handle search query
    $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    
    if (!empty($search_query)) {
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE name LIKE %s OR email LIKE %s OR mobile LIKE %s",
            "%$search_query%", "%$search_query%", "%$search_query%"
        ));
    } else {
        $registrations = $wpdb->get_results("SELECT * FROM $table_name");
    }

    echo '<div class="wrap"><h2>Event Registrations</h2>';

    // Search Form
    echo '<form method="GET" action="">
            <input type="hidden" name="page" value="event-qr-registrations">
            <input type="text" name="s" placeholder="Search by Name, Email, or Mobile" value="' . esc_attr($search_query) . '">
            <button type="submit" class="button">Search</button>
            <a href="' . admin_url('admin.php?page=event-qr-registrations') . '" class="button">Reset</a>
          </form><br>';

    echo '<table class="widefat fixed">
            <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>QR Code</th>
                    <th>Status</th>
					<th>Registration Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>';

    if ($registrations) {
        $serial_number = 1;
        foreach ($registrations as $row) {
            $status = $row->scanned ? '<span style="color:red;">Checked In</span>' : '<span style="color:green;">Not Scanned</span>';
            $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode(site_url("?verify_qr={$row->qr_code}"));
			 $registration_date = date('d M Y, h:i A', strtotime($row->created_at));

            echo "<tr id='row-{$row->id}'>
                    <td>{$serial_number}</td>
                    <td>{$row->name}</td>
                    <td>{$row->email}</td>
                    <td>{$row->mobile}</td>
                    <td><img src='{$qr_url}' alt='QR Code'></td>
                    <td>{$status}</td>
					<td>{$registration_date}</td>
                    <td>
                        <button class='delete-user button button-danger' data-id='{$row->id}'>Delete</button>
                    </td>
                  </tr>";

            $serial_number++;
        }
    } else {
        echo '<tr><td colspan="7">No records found.</td></tr>';
    }

    echo '</tbody></table></div>';

    // JavaScript for handling delete request
    echo '<script>
        jQuery(document).ready(function($) {
            $(".delete-user").click(function() {
                var userId = $(this).data("id");
                if(confirm("Are you sure you want to delete this user?")) {
                    $.ajax({
                        type: "POST",
                        url: ajaxurl,
                        data: {
                            action: "event_qr_delete_user",
                            user_id: userId
                        },
                        success: function(response) {
                            if (response.success) {
                                $("#row-" + userId).fadeOut(); // Hide deleted row
                            } else {
                                alert("Error: " + response.data);
                            }
                        }
                    });
                }
            });
        });
    </script>';
}
// Handle User Deletion via AJAX
function event_qr_delete_user() {
    if (current_user_can('manage_options') && isset($_POST['user_id'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'event_qr_codes';
        $user_id = intval($_POST['user_id']);

        $delete = $wpdb->delete($table_name, ['id' => $user_id]);

        if ($delete) {
            wp_send_json_success("User deleted successfully.");
        } else {
            wp_send_json_error("Failed to delete user.");
        }
    } else {
        wp_send_json_error("Unauthorized action.");
    }
}
add_action('wp_ajax_event_qr_delete_user', 'event_qr_delete_user');

