<?php
/**
 * Sync Page
 * 
 * Allows synchronizing products with WooCommerce
 * with improved batch processing to prevent timeouts
 */

// Enable detailed error reporting in development mode
if (!defined('APP_ENV') || APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Set maximum execution time to 5 minutes for sync operations
ini_set('max_execution_time', 300);

// Include required files
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/WooCommerce.php';
require_once 'includes/Product.php';
require_once 'includes/Sync.php';
require_once 'includes/functions.php';

// Initialize classes
$auth = new Auth();
$woocommerce = new WooCommerce();
$sync = new Sync();

// Require authentication
$auth->requireAuth();

// Check if this is an AJAX request for sync progress
if (isset($_GET['action']) && $_GET['action'] === 'progress') {
    $progress = $sync->getSyncProgress();
    header('Content-Type: application/json');
    echo json_encode($progress);
    exit;
}

// Check WooCommerce connection
$wc_connection = $woocommerce->testConnection();
$wc_connection_status = $wc_connection['success'] ? 'OK' : 'Error: ' . $wc_connection['message'];

// Process sync request
$sync_error = '';
$sync_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_sync'])) {
        // Reset sync state
        $sync->resetSyncState();
        set_flash_message('info', 'Sync process has been reset.');
        redirect('sync.php');
    } else if (isset($_POST['sync'])) {
        $full_sync = isset($_POST['full_sync']) && $_POST['full_sync'] == 1;
        
        try {
            // Perform sync (will handle continuation automatically)
            $sync_result = $sync->syncProducts($full_sync);
            
            if ($sync_result['is_complete']) {
                set_flash_message('success', 'Sync completed successfully. Added: ' . $sync_result['products_added'] . ', Updated: ' . $sync_result['products_updated']);
                redirect('sync.php');
            }
            
            // If not complete, we'll show the continuation UI
            
        } catch (Exception $e) {
            $sync_error = 'Exception during sync: ' . $e->getMessage();
            set_flash_message('error', $sync_error);
            redirect('sync.php');
        }
    }
}

// Get sync state and progress
$sync_state = $sync->getSyncState();
$sync_in_progress = ($sync_state['status'] === Sync::SYNC_STATE_IN_PROGRESS);
$sync_progress = $sync->getSyncProgress();

// Get sync logs
$sync_logs = $sync->getSyncLogs(10);
$last_sync = !empty($sync_logs) ? $sync_logs[0] : null;

// Include header
include 'templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Synchronize with WooCommerce</h1>
    <a href="/dashboard.php" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-tachometer-alt"></i> Back to Dashboard
    </a>
</div>

<?php if (APP_ENV === 'development' && !$wc_connection['success']): ?>
<div class="alert alert-warning">
    <strong>WooCommerce API Connection Issue:</strong> <?php echo htmlspecialchars($wc_connection['message']); ?>
    <br>
    <small>This message is only visible in development mode.</small>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Sync Status -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Sync Products</h6>
            </div>
            <div class="card-body">
                <?php if ($sync_in_progress): ?>
                <!-- Sync in progress UI -->
                <div class="alert alert-info">
                    <i class="fas fa-spinner fa-spin"></i> 
                    Sync in progress. This may take several minutes depending on the number of products.
                </div>
                
                <div class="progress mb-3">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                         role="progressbar" 
                         id="sync-progress-bar"
                         style="width: <?php echo $sync_progress['percent']; ?>%" 
                         aria-valuenow="<?php echo $sync_progress['percent']; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                        <?php echo $sync_progress['percent']; ?>%
                    </div>
                </div>
                
                <div id="sync-progress-info" class="text-center mb-4">
                    <p>
                        <strong>Products Processed:</strong> <span id="processed-count"><?php echo $sync_progress['processed']; ?></span>
                        <?php if ($sync_progress['total'] > 0): ?>
                        of <span id="total-count"><?php echo $sync_progress['total']; ?></span>
                        <?php endif; ?>
                    </p>
                    <p>
                        <strong>Products Added:</strong> <span id="added-count"><?php echo $sync_progress['products_added']; ?></span>
                        <strong>Products Updated:</strong> <span id="updated-count"><?php echo $sync_progress['products_updated']; ?></span>
                    </p>
                </div>
                
                <div class="d-flex justify-content-between">
                    <form action="" method="post" class="d-inline">
                        <input type="hidden" name="reset_sync" value="1">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-stop"></i> Cancel Sync
                        </button>
                    </form>
                    
                    <form action="" method="post" id="continue-sync-form" class="d-inline">
                        <input type="hidden" name="sync" value="1">
                        <input type="hidden" name="full_sync" value="<?php echo $sync_state['full_sync'] ? '1' : '0'; ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-play"></i> Continue Sync
                        </button>
                    </form>
                </div>
                
                <?php else: ?>
                <!-- Normal sync UI -->
                <p>
                    Synchronize your physical inventory with products from your WooCommerce store. 
                    This will import any new products from WooCommerce and update existing product information.
                </p>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    Product stock levels in your physical inventory will NOT be overwritten during synchronization.
                </div>
                
                <div id="sync-status">
                    <?php if (!empty($sync_error)): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($sync_error); ?>
                    </div>
                    <?php elseif ($last_sync): ?>
                    <div class="alert alert-<?php echo $last_sync->status === 'success' ? 'success' : 'danger'; ?>">
                        Last sync: <?php echo format_date($last_sync->sync_date); ?><br>
                        Products added: <?php echo $last_sync->products_added; ?><br>
                        Products updated: <?php echo $last_sync->products_updated; ?><br>
                        Status: <?php echo ucfirst($last_sync->status); ?>
                        <?php if ($last_sync->status === 'error' && !empty($last_sync->details)): ?>
                        <br>Error: <?php echo htmlspecialchars($last_sync->details); ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        No synchronization has been performed yet.
                    </div>
                    <?php endif; ?>
                </div>
                
                <form action="" method="post">
                    <div class="d-grid gap-2 mb-3">
                        <button type="submit" name="sync" value="1" class="btn btn-primary btn-lg">
                            <i class="fas fa-sync"></i> Sync Products Now
                        </button>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="full-sync-check" name="full_sync" value="1">
                        <label class="form-check-label" for="full-sync-check">
                            Full sync (update all product data, not just new products)
                        </label>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Scheduled Sync -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Scheduled Sync</h6>
            </div>
            <div class="card-body">
                <p>
                    The system is configured to automatically synchronize with WooCommerce every Monday.
                </p>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    Automatic sync will only import newly added products, not update existing ones.
                </div>
                
                <div class="mt-3">
                    <h6>Next scheduled sync:</h6>
                    <p>
                        <?php
                        // Calculate next Monday
                        $now = time();
                        $days_to_monday = 1 - date('N', $now);
                        if ($days_to_monday <= 0) {
                            $days_to_monday += 7;
                        }
                        $next_monday = strtotime("+{$days_to_monday} days", $now);
                        echo date('l, F j, Y', $next_monday);
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Sync Logs -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Sync History</h6>
            </div>
            <div class="card-body">
                <div id="sync-logs" class="sync-logs">
                    <?php if (empty($sync_logs)): ?>
                    <p class="text-center text-muted">No sync logs available.</p>
                    <?php else: ?>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Added</th>
                                <th>Updated</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sync_logs as $log): ?>
                            <tr>
                                <td><?php echo format_date($log->sync_date); ?></td>
                                <td><?php echo $log->products_added; ?></td>
                                <td><?php echo $log->products_updated; ?></td>
                                <td class="sync-status-<?php echo $log->status; ?>"><?php echo ucfirst($log->status); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Sync Help -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Sync Options</h6>
            </div>
            <div class="card-body">
                <h6>Regular Sync</h6>
                <p>Imports new products and updates basic information for existing products.</p>
                
                <h6>Full Sync</h6>
                <p>Updates all product data from WooCommerce, but preserves your physical inventory stock levels.</p>
                
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    For large catalogs, sync operations will process products in batches to avoid timeouts.
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($sync_in_progress): ?>
<script>
// Auto-refresh progress every 2 seconds
var progressInterval;

function updateSyncProgress() {
    $.ajax({
        url: 'sync.php?action=progress',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data.in_progress) {
                // Update progress bar
                $('#sync-progress-bar').css('width', data.percent + '%');
                $('#sync-progress-bar').attr('aria-valuenow', data.percent);
                $('#sync-progress-bar').text(data.percent + '%');
                
                // Update counts
                $('#processed-count').text(data.processed);
                $('#total-count').text(data.total);
                $('#added-count').text(data.products_added);
                $('#updated-count').text(data.products_updated);
                
                // If we're at 100%, reload the page
                if (data.percent >= 100) {
                    clearInterval(progressInterval);
                    location.reload();
                }
            } else {
                // Sync is no longer in progress, reload the page
                clearInterval(progressInterval);
                location.reload();
            }
        },
        error: function() {
            // On error, stop polling
            clearInterval(progressInterval);
        }
    });
}

$(document).ready(function() {
    // Start progress updates
    progressInterval = setInterval(updateSyncProgress, 2000);
    
    // Auto-continue the sync after a short delay
    setTimeout(function() {
        $('#continue-sync-form').submit();
    }, 1000);
});
</script>
<?php endif; ?>

<?php
// Include footer
include 'templates/footer.php';
?>