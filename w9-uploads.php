<?php
/**
 * Plugin Name: STF W9 Uploads
 * Plugin URI:  https://www.studiotenfour.com
 * Description: Handles front-end W9 file uploads
 * Version: 1.0
 * Author: Laud Tetteh
 * Author URI: https://www.studiotenfour.com
 * License: GPL2
 */

class W9Uploads {
    // Setup options variables
    protected $option_name = 'stf_w9_uploads';
    // Default options values
    protected $data = [
        'jq_theme' => 'start',
        'managers' => [4, 13], //Plugin Owners (Laud & Anna)
    ];

    public function __construct() {
        $wp_upload = wp_upload_dir();
        $this->basedir = $wp_upload['basedir'];
        $this->upload_dir = $this->basedir . '/w9-uploads'; // W9 Uploads directory inside /uploads
        $this->w9_page_ids = ['stf_w9_uploads_menu_page', 'toplevel_page_stf_w9_uploads_menu_page'];
        $this->plugin_owners = [4, 13]; // Anna Callahan and Laud Tetteh
        $old_options = get_option('stf_pdf_uploads'); // Grab plugin options from db
        $options = get_option('stf_w9_uploads'); // Grab plugin options from db

        if( get_option('stf_pdf_uploads') && !get_option('stf_w9_uploads') ) {
            // Move plugin options old stf_pdf_uploads row to stf_w9_uploads row
            update_option('stf_w9_uploads', $old_options);
            // Remove old options row
            delete_option('stf_pdf_uploads');
        }

        if( get_option('stf_w9_uploads') ) {
            // Grab plugin options from db
            $_managers = $options['managers'];
            // If W9 Managers set in db, add them to Plugin Owners (Laud & Anna)
            if( $_managers ) {
                $this->allowed_w9_managers = array_unique(array_merge($this->plugin_owners, $_managers));
            }

        } else {
            // Else, restrict access to just the Plugin Owners (Laud & Anna)
            $this->allowed_w9_managers = $this->plugin_owners;
        }
        // Set up shortcode for front end form
        add_shortcode('stf_w9_upload_form', array($this, 'w9_shortcode_handler'));
        // Handles form submission on front end
        add_action('init', array($this, 'stf_init'));

        if( is_admin() ) {
            // Backend functionality: Register this plugin
            add_action( 'admin_menu', array($this, 'stf_w9_uploads_register'), 9990 );
            // Backend functionality: For users with on W9 Manager access, remove all other menus
            add_action( 'admin_menu', array($this, 'stf_remove_admin_menus'), 9991 );
            // Backend functionality: For users not designated for this plugin, hide plugin page/menu
            add_action( 'current_screen', array($this, 'stf_block_access'), 9992 );

            // Backend functionality: Activate this plugin, plus scripts and styles
            register_activation_hook( __FILE__ , array($this, 'stf_w9_uploads_activate'));
        }
    }

    // Backend functionality: For users not designated for this plugin, hide plugin page/menu
    public function stf_block_access() {
        global $current_user, $submenu, $menu, $pagenow;
        wp_get_current_user();
        $screen = get_current_screen();

        if( in_array($screen->id, $this->w9_page_ids) && !in_array($current_user->ID, $this->allowed_w9_managers) ) {

            wp_die( '<pre> Sorry, you don\'t have permission to access this page.</pre>' );

        } else {

            return true;
        }
    }

    // Backend functionality: For users with on W9 Manager access, remove all other menus
    public function stf_remove_admin_menus() {
        global $current_user, $menu, $submenu;
        wp_get_current_user();
        $screen = get_current_screen();
        // Additional pages to be hidden: Past Grants && TAR CSV Plugins
        $other_pages = [
            'stf_tar_csv_to_db_menu_page',
            'wp_csv_to_db_menu_page',
        ];
        // If user is a specially designator W9 Manager
        if( $this->stf_user_is_w9_manager() ) {
            // Then hide all other menus
            foreach( $submenu as $k => $v ) {
                remove_menu_page($k);
            }
            // Then hide all additional menus
            foreach( $other_pages as $k ) {
                remove_menu_page($k);
            }
        // If user is not a specially designator W9 Manager
        } elseif( !in_array($current_user->ID, $this->allowed_w9_managers) ) {
            // But is blocked from accessing the W9 Plugin
            foreach( $this->w9_page_ids as $page ) {
                // Hide this plugin
                remove_menu_page($page);
            }
        }
    }

    // Determine if current user is a specially designator W9 Manager
    public function stf_user_is_w9_manager() {
        global $current_user;

        $roles = $current_user->roles;

        if( count($roles) == 2 && in_array('administrator', $roles) && in_array('w9_manager', $roles) ) {

            return true;

        }

        return false;
    }

    // Handles front end functionality for the W9 Plugin
    public function stf_init() {
        // Handle plugin front end page
        $error = '';
        $referrer = home_url(). '/grants-artist-calls/w-9-upload';

        if (!empty($_POST['nonce_w9_upload_form'])) {

            if (!wp_verify_nonce($_POST['nonce_w9_upload_form'], 'handle_w9_upload_form')) {
                // If WP token fails, disallow access to the plugin
                $status = 'not_authorized';
                wp_redirect($referrer . '?status=' . $status);

                exit();

            } else {
                // If WP token success, access to the plugin
                if (empty($_POST['name'])) {
                    // If 'name' text field is empty
                    $status = 'empty_name';
                    // Return to /w-9-upload page with status code
                    wp_redirect($referrer . '?status=' . $status);

                    exit();

                } else {
                    // Proceed to upload file
                    $filename = $_POST['name'];
                    $this->stf_upload_files($referrer, $filename);
                }
            }
        }
    }

    // Handles creation of w9-uploads directory on plugin activation
    public function stf_create_w9_uploads_dir() {
        // Create TAR Image upload directory if none exists
        $upload_dir = $this->upload_dir;
        if (! is_dir($upload_dir)) {
           mkdir( $upload_dir, 0700 );

           return true;
        }

        return false;
    }

    public function stf_move_existing_files() {
        // ** THIS WILL ONLY HAPPEN ONCE
        // Identify Source directory
        $source = $this->basedir . '/pdf-uploads';
        $old_dir_exists = false;
        $delete = [];

        if( is_dir($source) ) {
            $old_dir_exists = true;
            // Get array of all source files
            $files = scandir($source);
            // Identify Destination directory
            $destination = $this->upload_dir;
            // Cycle through all source files
            foreach ($files as $file) {
                if (in_array($file, array(".","..", ".DS_Store"))) continue;
                // If we copied this successfully, mark it for deletion
                if (copy($source.'/'.$file, $destination.'/'.$file)) {
                    $delete[] = $source.'/'.$file;
                }
            }
            // Delete all successfully-copied files
            foreach ($delete as $file) {
                unlink($file);
            }
        }

        if( $old_dir_exists ) {

            if( rmdir($source) ) {

                return true;
            }
        }

        return false;
    }

    // Handles plugin activations
    public function stf_w9_uploads_activate() {
        // On activation, set up options for this plugin in db
        if( get_option($this->option_name) ) {
            update_option($this->option_name, $this->data);
        } else {
            add_option($this->option_name, $this->data);
        }
        // Then create the w9-uploads dir if none
        $this->stf_create_w9_uploads_dir();
        //Then move existing files from old "pdf-uploads" dir to new "w9-uploads"
        $this->stf_move_existing_files();
    }

    // Handles plugin registration: menu group, scripts and styles
    public function stf_w9_uploads_register() {
        $stf_w9_uploads_page = add_menu_page( __('W9 Uploads','stf_w9_uploads'), __('W9 Uploads','stf_w9_uploads'), 'manage_options', 'stf_w9_uploads_menu_page', array( $this, 'stf_w9_uploads_menu_page' )); // Add submenu page to "Settings" link in WP
        add_action( 'admin_print_scripts-' . $stf_w9_uploads_page, array( $this, 'stf_w9_uploads_admin_scripts' ) );  // Load our admin page scripts (our page only)
        add_action( 'admin_print_styles-' . $stf_w9_uploads_page, array( $this, 'stf_w9_uploads_admin_styles' ) );  // Load our admin page stylesheet (our page only)
    }

    // plugin scripts
    public function stf_w9_uploads_admin_scripts() {
        wp_enqueue_script('media-upload');  // For WP media uploader
        wp_enqueue_script('thickbox');  // For WP media uploader
        wp_enqueue_script('jquery-ui-tabs');  // For admin panel page tabs
        wp_enqueue_script('jquery-ui-dialog');  // For admin panel popup alerts
        wp_enqueue_script( 'stf_w9_uploads', plugins_url( '/js/admin_page.js', __FILE__ ), array('jquery') );  // Apply admin page scripts
    }

    // plugin styles
    public function stf_w9_uploads_admin_styles() {
        wp_enqueue_style('thickbox');  // For WP media uploader
        wp_enqueue_style('sdm_admin_styles', plugins_url( '/css/admin_page.css', __FILE__ ));
        // Get option for jQuery theme
        $options = get_option($this->option_name);
        $select_theme = isset($options['jq_theme']) ? $options['jq_theme'] : 'start';
        ?>
        <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/<?php echo $select_theme; ?>/jquery-ui.css">
        <?php
    }

    // Front end form shortcode
    function w9_shortcode_handler($atts) {
        return

        "<form method='post' enctype='multipart/form-data' action='" . esc_url( admin_url('admin-post.php') ) . "'>

            <p>
                <label for='name'>
                    First and Last Name or Name of Organization/Business
                    <input name='name' id='name' value='' size='22' tabindex='1' type='text' required>
                </label>
            </p>
            <p>
                <label for='file'>
                    <input name='file' id='file' value='' size='22' tabindex='2' type='file' class='medium' accept='application/pdf, .pdf, .PDF, image/jpeg, image/jpg, .jpg, .JPG, .JPEG, .jpeg'>
                </label>
            </p>

            " . wp_nonce_field('handle_w9_upload_form', 'nonce_w9_upload_form') . "

            <p>
                <input name='submit' id='submit' tabindex='5' value='Submit' type='submit' class='button'>
            </p>
        </form>";
    }

    // Handles the actual file upload
    public function stf_upload_files($referrer, $filename) {
        $status = '';
        $datestamp = date('n.j.Y-h.iA');

        if( 'POST' == $_SERVER['REQUEST_METHOD']  ) {
            $process_file = false;

            // Check if FILES object contains a file
            if ( $_FILES['file']['name'][0] == "" ) {

                $status = 'no_file';

            } else {
                // If there is a file, get the extension
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);

                if( $ext !== "pdf" && $ext !=='PDF' && $ext !=='jpg' && $ext !=='JPG' && $ext !=='jpeg' && $ext !=='JPEG'  ) {

                    $status = 'not_valid';

                } else {
                    // Grab the string from the 'name' text field, strip spaces
                    $_name = str_replace(' ', '-', "$filename");
                    // Combine name with datestamp and extension to get final file name
                    $name = $_name.'_'."$datestamp.$ext";
                    // Move temp file to wp-content/uploads/w9-uploads dir
                    $process_file = move_uploaded_file($_FILES['file']['tmp_name'], $this->upload_dir.'/'.$name);

                    if ( $process_file == true ) {
                        // On success, set status
                        $status = 'success';

                    } else {
                        // On failure, set status
                        $status = 'fail';
                    }
                }
            }
        }

        // Back to front end form page with status code
        wp_redirect($referrer . '?status=' . $status);

        exit();
    }

    // Counts the number of w9 files inside the W9 Uploads dir
    public function stf_count_files() {
        $filecount = 0;
        $pdfFiles = glob($this->upload_dir . '/*.pdf');
        $PDFFiles = glob($this->upload_dir . '/*.PDF');
        $jpgFiles = glob($this->upload_dir . '/*.jpg');
        $JPGFiles = glob($this->upload_dir . '/*.JPG');
        $jpegFiles = glob($this->upload_dir . '/*.jpeg');
        $JPEGFiles = glob($this->upload_dir . '/*.JPEG');

        $files = array_merge($pdfFiles, $PDFFiles, $jpgFiles, $JPGFiles, $jpegFiles, $JPEGFiles);

        if ( $files !== false ) {
            $filecount = count( $files );

            return $filecount;
        }

        return 0;
    }

    // Get a list of file names inside the W9 Uploads dir
    public function stf_get_filelist() {
        $html = '';
        $files = $this->stf_get_filenames();

        if( !empty($files) ) {
            // Make and html list
            $html .= '<ol class="flush-left">';

            foreach( $files as $filename ) {
                $filename = str_replace("$this->upload_dir/", '', $filename);
                $html .= '<li>'. $filename .'</li>';
            }

            $html .= '</ol>';
        }

        return $html;
    }

    // Grab names of w9 file names from the W9 Uploads dir
    public function stf_get_filenames() {
        $filenames = [];

        foreach( glob($this->upload_dir . '/*.pdf') as $filename ) {
            $filenames[] = $filename;
        }

        foreach( glob($this->upload_dir . '/*.PDF') as $filename ) {
            $filenames[] = $filename;
        }

        foreach( glob($this->upload_dir . '/*.jpg') as $filename ) {
            $filenames[] = $filename;
        }

        foreach( glob($this->upload_dir . '/*.JPG') as $filename ) {
            $filenames[] = $filename;
        }

        foreach( glob($this->upload_dir . '/*.jpeg') as $filename ) {
            $filenames[] = $filename;
        }

        foreach( glob($this->upload_dir . '/*.JPEG') as $filename ) {
            $filenames[] = $filename;
        }

        return $filenames;
    }

    // Handles compressing, naming and downloading of W9s from the the W9 Uploads dir
    public function stf_download_archive() {
        $datestamp = date('n-j-Y');
        // Customize the name of the zipped archive
        $archive_file_name = '4culture-w9-uploads_'."$datestamp.zip";

        $files = $this->stf_get_filenames();
        $zip = new ZipArchive;

        // Create and name new archive
        $zip->open($archive_file_name, ZipArchive::CREATE);

        foreach ($files as $file) {
            // Add files to archive
            $zip->addFile($file, basename($file));
        }

        $zip->close();
        header('Content-Type: application/zip');
        header("Content-disposition: attachment; filename=$archive_file_name");
        header('Content-Length: ' . filesize($archive_file_name));
        ob_clean();
        flush();
        readfile($archive_file_name);
        unlink($archive_file_name);

        return true;
    }

    // Handles emptying the W9 Uploads dir on button click
    public function stf_delete_files() {

        $files = $this->stf_get_filenames();

        foreach($files as $file) {

            if(is_file($file))
                unlink($file); // delete file
        }

        return true;
    }

    // Returns true of W9 Uploads dir is empty
    public function stf_is_dir_empty() {
        // Check for files with .pdf, .PDF, jpg & JPEG extensions
        if( count(glob($this->upload_dir . '/*.pdf')) === 0
            && count(glob($this->upload_dir . '/*.PDF')) === 0
            && count(glob($this->upload_dir . '/*.jpg')) === 0
            && count(glob($this->upload_dir . '/*.JPG')) === 0
            && count(glob($this->upload_dir . '/*.jpeg')) === 0
            && count(glob($this->upload_dir . '/*.JPEG')) === 0
        ) {
            return true;
        }

        return false;
    }

    // Handles manager assigment under the 'Settings' tab of the plugin page
    public function stf_assign_manager() {
        $selected = array_map('intval', $_POST['managers']);
        $options = get_option('stf_w9_uploads', false);

        if( $options ) {
            // If 'stf_w9_uploads' option already exists in db
            if( empty($selected) ) {
                // If no new managers selected, default to the Plugin Owners (Laud and Anna)
                $managers = $this->plugin_owners;

            } else {
                // If new managers selected, then add them to the Plugin Owners
                $managers = array_unique( array_merge($this->plugin_owners, $selected) );
            }

            $options['managers'] = $managers;
            // Update db options with new manager list
            update_option('stf_w9_uploads', $options);

            return true;

        } else {
            // If 'stf_w9_uploads' option doesn't exist in db
            $options = [
                'jq_theme' => 'start',
            ];

            if( empty($selected) ) {

                $managers = $this->plugin_owners;

            } else {

                $managers = array_unique( array_merge($this->plugin_owners, $selected) );
            }

            $options['managers'] = $managers;
            // Then create and set 'stf_w9_uploads' options
            add_option('stf_w9_uploads', $options);

            return true;
        }

        return false;
    }

    // Grab list of allowed users:  Owners + Managers
    public function stf_get_allowed_w9_managers() {

        if( get_option('stf_w9_uploads') ) {

            $options = get_option('stf_w9_uploads');

            if( isset($options['managers']) ) {

                $_managers = $options['managers'];

                if( $_managers ) {
                    $allowed_w9_managers = array_unique(array_merge($this->plugin_owners, $_managers));
                }

                return $allowed_w9_managers;
            }

        } else {

            $allowed_w9_managers = $this->plugin_owners;

            return $allowed_w9_managers;
        }

        return [];
    }

    // Get current user ID
    public function get_current_user() {
        global $current_user;
        wp_get_current_user();

        return $current_user->ID;
    }

    // Handles HTML (tabs, alerts, forms) for Plugin page on backend
    public function stf_w9_uploads_menu_page() {
        $error_message = '';
        $success_message = '';
        $message_info_style = '';
        $file_count = $this->stf_count_files();
        $filelist = $this->stf_get_filelist();

        if(!current_user_can('manage_options')){
            wp_die('Error! Only site admin can perform this operation');
        }

        // If button is pressed to "Download Archive"
        if( isset($_POST['download_files']) ) {

            if( !is_dir($this->upload_dir) ) {
                if( $this->stf_create_w9_uploads_dir() == false ) {

                    $error_message .='<br /> * Error: '.__('Couldn\'t find the W9 Uploads directory ("wp-content/uploads/w9-uploads/"). Please make sure it exists and try again.','stf_w9_uploads');
                } else {

                    $this->stf_download_archive();
                    $success_message .= '<br />* Success!: '.__('Archive exported successfully','stf_w9_uploads').'<br />';
                }

            } else {

                // If the "Select Input File" input field is empty
                if( 0 == $file_count ) {

                    $error_message .= '<br />* Error: '.__('The W9 Uploads directory is empty.','stf_w9_uploads').'<br />';

                } else {

                    $this->stf_download_archive();

                    $success_message .= '<br />* Success!: '.__('Archive exported successfully','stf_w9_uploads').'<br />';
                }
            }
        }

        // If button is pressed to "Delete Files"
        if( isset($_POST['delete_files']) ) {

            if( !is_dir($this->upload_dir) ) {
                if( $this->stf_create_w9_uploads_dir() == false ) {

                    $error_message .='<br /> * Error: '.__('Couldn\'t find the W9 Uploads directory ("wp-content/uploads/w9-uploads/"). Please make sure it exists and try again.','stf_w9_uploads');
                } else {

                    $this->stf_delete_files();
                    $success_message .= '<br />* Success!: '.__('Files deleted successfully','stf_w9_uploads').'<br />';
                }

            } else {

                // If the "Select Input File" input field is empty
                if( 0 == $file_count ) {

                    $error_message .= '<br />* Error: '.__('The W9 Uploads directory is empty.','stf_w9_uploads').'<br />';

                } else {

                    $this->stf_delete_files();
                    $success_message .= '<br />* Success!: '.__('Files deleted successfully','stf_w9_uploads').'<br />';
                }
            }
        }

        // If button is pressed to "Delete Files"
        if( isset($_POST['assign_manager']) ) {

            if( $this->stf_assign_manager() == true ) {
                $success_message .= '<br />* Success!: '.__('Managers updated successfully','stf_w9_uploads').'<br />';
            } else {
                $error_message .= '<br />* Error: '.__('Something went wrong. Managers could not be updated.','stf_w9_uploads').'<br />';
            }
        }

        ?>

        <div class="wrap">

            <?php
                // If there is a message - info-style
                if(!empty($message_info_style)) {
                    echo '<div class="info_message_dismiss">';
                    echo $message_info_style;
                    echo '<br /><em>('.__('click to dismiss','stf_w9_uploads').')</em>';
                    echo '</div>';
                }

                // If there is an error message
                if(!empty($error_message)) {
                    echo '<div class="error_message">';
                    echo $error_message;
                    echo '<br /><em>('.__('click to dismiss','stf_w9_uploads').')</em>';
                    echo '</div>';
                }

                // If there is a success message
                if(!empty($success_message)) {
                    echo '<div class="success_message">';
                    echo $success_message;
                    echo '<br /><em>('.__('click to dismiss','stf_w9_uploads').')</em>';
                    echo '</div>';
                }
            ?>

            <h2><?php _e('4Culture W9 Uploads','stf_w9_uploads'); ?></h2>
            <p>Download uploaded W9s.</p>

            <div id="tabs">
                <ul>
                    <li><a href="#tabs-1" class="tab-link"><?php _e('Download','stf_w9_uploads'); ?></a></li>
                    <li><a href="#tabs-2" class="tab-link"><?php _e('Delete','stf_w9_uploads'); ?></a></li>
                    <li><a href="#tabs-3" class="tab-link"><?php _e('Reports','stf_w9_uploads'); ?></a></li>

                    <?php if( in_array($this->get_current_user(), $this->plugin_owners) ) {?>
                        <li><a href="#tabs-4" class="tab-link"><?php _e('Settings','stf_w9_uploads'); ?></a></li>
                    <?php }?>
                </ul>

                <div id="tabs-1" class="tab">
                    <form action="" method="POST">
                        <table class="form-table">

                            <tr valign="top"><th scope="row"><?php _e('Download Archive:','stf_w9_uploads'); ?></th>
                                <td>
                                    <input id="download_files" name="download_files" type="submit" class="button-primary" value="Download All W9s">

                                    <br><br><?php _e('This will compress and download all W9s in "wp-content/uploads/w9-uploads/".','stf_w9_uploads'); ?>

                                    <?php

                                    $_file_count = $this->stf_count_files();
                                    if( $_file_count == 1): ?>
                                        <p>There is currently <strong><?php echo $_file_count ?></strong> W9 file in the <strong>W9 Uploads</strong> folder (see Reports tab).</p>
                                    <?php else: ?>
                                        <p>There are currently <strong><?php echo $_file_count ?></strong> W9 files in the <strong>W9 Uploads</strong> folder (see Reports tab).</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div> <!-- End tab 1 -->

                <div id="tabs-2" class="tab">
                    <form action="" method="POST">
                        <table class="form-table">

                            <tr valign="top"><th scope="row"><?php _e('Delete Files:','stf_w9_uploads'); ?></th>
                                <td>
                                    <input id="delete_files" name="delete_files" type="submit" class="button-danger" value="Delete All W9s">

                                    <br><br><?php _e('This will delete all W9s in "wp-content/uploads/w9-uploads/".','stf_w9_uploads'); ?>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div> <!-- End tab 2 -->

                <div id="tabs-3" class="tab">
                    <p class="submit">
                        <input id="refresh_button" name="refresh_button" type="submit" class="refresh_button button-primary" value="<?php _e('Refresh Reports', 'stf_w9_uploads') ?>"/>
                    </p>

                    <?php
                        $_file_count = $this->stf_count_files();
                        $_filelist = $this->stf_get_filelist();
                    ?>
                    <h4>FILE TOTAL: <strong><?php echo $_file_count ?></strong></h4>
                    <?php echo $_filelist ?>

                    <p class="submit">
                        <input id="refresh_button" name="refresh_button" type="submit" class="refresh_button button-primary" value="<?php _e('Refresh Reports', 'stf_w9_uploads') ?>"/>
                    </p>
                </div> <!-- End tab 3 -->

                <?php if( in_array($this->get_current_user(), $this->plugin_owners) ) {?>
                    <div id="tabs-4" class="tab">
                        <form action="" method="POST">
                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row">
                                        <?php _e('Assign W9 managers:','stf_w9_uploads'); ?>
                                    </th>
                                    <td>
                                        <?php $users = get_users();

                                        if( $users ): ?>
                                            <div class="stf-user-list">
                                                <?php foreach( $users as $user ) {
                                                    $checked = in_array($user->id, $this->stf_get_allowed_w9_managers() ) ? ' checked' : '';

                                                    $disabled = in_array($user->id, $this->plugin_owners) ? ' disabled' : '';

                                                    echo '<label>
                                                    <input type="checkbox" name="managers[]" value="'. $user->id.'"'. $checked.$disabled. ' />
                                                    '. $user->display_name .' <span>('. $user->user_email  .')</span>
                                                    </label><br>';
                                                }?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <input id="assign_manager" name="assign_manager" type="submit" class="button-primary" value="Submit">
                            </p>

                        </form>
                    </div> <!-- End tab 4 -->
                <?php }?>

            </div> <!-- End #tabs -->
        </div> <!-- End page wrap -->
        <?php
    }
}

$W9Uploads = new W9Uploads();
