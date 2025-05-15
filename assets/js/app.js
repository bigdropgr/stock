/**
 * Custom JavaScript for the Physical Store Inventory System
 */

$(document).ready(function() {
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
    
    // Search functionality
    // Search functionality - simple form submission
    // We're not using the AJAX version for now to simplify
    $('#search-input').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            $(this).closest('form').submit();
        }
    });
    
    // Display search results
    function displaySearchResults(results) {
        var html = '';
        
        if (results.length === 0) {
            html = '<div class="alert alert-info">No products found matching your search.</div>';
        } else {
            html = '<div class="row">';
            
            $.each(results, function(index, product) {
                var stockClass = 'stock-high';
                if (product.stock <= 5) {
                    stockClass = 'stock-low';
                } else if (product.stock <= 10) {
                    stockClass = 'stock-medium';
                }
                
                html += '<div class="col-md-4 mb-4">';
                html += '<div class="card product-card">';
                
                if (product.image_url) {
                    html += '<img src="' + product.image_url + '" class="card-img-top" alt="' + product.title + '">';
                } else {
                    html += '<div class="text-center p-4 bg-light card-img-top"><i class="fas fa-box fa-4x text-muted"></i></div>';
                }
                
                html += '<div class="card-body">';
                html += '<h5 class="card-title">' + product.title + '</h5>';
                html += '<p class="product-sku mb-1">SKU: ' + product.sku + '</p>';
                html += '<p class="mb-1">Category: ' + (product.category || 'N/A') + '</p>';
                html += '<p class="product-price mb-1">Price: €' + parseFloat(product.price).toFixed(2) + '</p>';
                html += '<p class="product-stock mb-2">Stock: <span class="' + stockClass + '">' + product.stock + '</span></p>';
                html += '<a href="product.php?id=' + product.id + '" class="btn btn-primary">Edit</a>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
            
            html += '</div>';
        }
        
        $('#search-results').html(html);
    }
    
    // Quick stock update
    $('.quick-stock-update').on('click', function(e) {
        e.preventDefault();
        
        var productId = $(this).data('product-id');
        var currentStock = parseInt($('#current-stock-' + productId).text());
        
        $('#quick-stock-product-id').val(productId);
        $('#quick-stock-value').val(currentStock);
        $('#quickStockModal').modal('show');
    });
    
    // Stock update form
    $('#quick-stock-form').on('submit', function(e) {
        e.preventDefault();
        
        var productId = $('#quick-stock-product-id').val();
        var newStock = $('#quick-stock-value').val();
        
        $.ajax({
            url: 'api/products.php',
            type: 'POST',
            data: {
                action: 'update_stock',
                product_id: productId,
                stock: newStock
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#quickStockModal').modal('hide');
                    showAlert('Stock updated successfully', 'success');
                    
                    // Update the displayed stock value
                    var stockElement = $('#current-stock-' + productId);
                    stockElement.text(newStock);
                    
                    // Update stock class
                    stockElement.removeClass('stock-high stock-medium stock-low');
                    if (newStock <= 5) {
                        stockElement.addClass('stock-low');
                    } else if (newStock <= 10) {
                        stockElement.addClass('stock-medium');
                    } else {
                        stockElement.addClass('stock-high');
                    }
                } else {
                    showAlert('Error updating stock: ' + response.message, 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error updating stock. Please try again.', 'danger');
                console.error(error);
            }
        });
    });
    
    // Sync products
    $('#sync-products-btn').on('click', function(e) {
        e.preventDefault();
        
        // Disable button and show loading
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Syncing...');
        
        // Show sync status
        $('#sync-status').html('<div class="alert alert-info">Sync in progress... This may take a few minutes.</div>');
        
        $.ajax({
            url: 'api/sync.php',
            type: 'POST',
            data: {
                action: 'sync_products'
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).html('Sync Products');
                
                if (response.status === 'success') {
                    $('#sync-status').html('<div class="alert alert-success">Sync completed successfully.<br>Products added: ' + response.products_added + '<br>Products updated: ' + response.products_updated + '<br>Duration: ' + response.duration.toFixed(2) + ' seconds</div>');
                    
                    // Reload sync logs
                    loadSyncLogs();
                } else {
                    $('#sync-status').html('<div class="alert alert-danger">Sync failed: ' + response.errors.join('<br>') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html('Sync Products');
                $('#sync-status').html('<div class="alert alert-danger">Error during sync. Please try again.</div>');
                console.error(error);
            }
        });
    });
    
    // Load sync logs
    function loadSyncLogs() {
        $.ajax({
            url: 'api/sync.php',
            type: 'GET',
            data: {
                action: 'get_sync_logs'
            },
            dataType: 'json',
            success: function(response) {
                if (response.logs && response.logs.length > 0) {
                    displaySyncLogs(response.logs);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading sync logs:', error);
            }
        });
    }
    
    // Display sync logs
    function displaySyncLogs(logs) {
        var html = '<table class="table table-striped">';
        html += '<thead><tr><th>Date</th><th>Products Added</th><th>Products Updated</th><th>Status</th></tr></thead>';
        html += '<tbody>';
        
        $.each(logs, function(index, log) {
            var statusClass = log.status === 'success' ? 'sync-status-success' : 'sync-status-error';
            
            html += '<tr>';
            html += '<td>' + formatDate(log.sync_date) + '</td>';
            html += '<td>' + log.products_added + '</td>';
            html += '<td>' + log.products_updated + '</td>';
            html += '<td class="' + statusClass + '">' + log.status + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#sync-logs').html(html);
    }
    
    // Helper function to format date
    function formatDate(dateString) {
        var date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }
    
    // Show alert
    function showAlert(message, type) {
        var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">';
        alertHtml += message;
        alertHtml += '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        alertHtml += '</div>';
        
        $('#alert-container').html(alertHtml);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $('#alert-container .alert').alert('close');
        }, 5000);
    }
    
    // Load sync logs if on sync page
    if ($('#sync-logs').length > 0) {
        loadSyncLogs();
    }
    
    // Dashboard refresh
    $('#refresh-dashboard').on('click', function(e) {
        e.preventDefault();
        location.reload();
    });
});