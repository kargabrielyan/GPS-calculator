jQuery(document).ready(function($) {
    console.log('Machine Calculator Admin JS loaded');
    console.log('jQuery version:', $.fn.jquery);
    
    // Управление вкладками
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        console.log('Tab clicked:', $(this).attr('href'));
        
        // Убираем активность со всех вкладок и контента
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').hide();
        
        // Активируем выбранную вкладку
        $(this).addClass('nav-tab-active');
        
        // Показываем соответствующий контент
        const targetId = $(this).attr('href');
        console.log('Showing content:', targetId);
        $(targetId).show();
        
    });
    
    
    
    
    
    // Сохранение правила - универсальная функция для всех форм
    $('.rule-form').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const calcType = form.data('calc-type');
        
        console.log('Form submitted:', form.attr('id'), 'calcType:', calcType);
        
        let formData = {
            action: 'save_calculator_rule',
            nonce: machineCalculatorAdmin.nonce,
            calc_type: calcType
        };
        
        // Разная логика валидации для каждого типа
        if (calcType === 'type1') {
            // Для движения машин: тип зоны + цена за машину
            formData.zone_type = form.find('select[name="zone_type"]').val();
            formData.filter_price = form.find('input[name="filter_price"]').val();
            
            if (!formData.zone_type || !formData.filter_price) {
                alert('Խնդրում ենք լրացնել բոլոր դաշտերը');
                return;
            }
        } else if (calcType === 'type2') {
            // Для топлива: только цена за бак
            formData.tank_price = form.find('input[name="tank_price"]').val();
            
            if (!formData.tank_price) {
                alert('Խնդրում ենք լրացնել բոլոր դաշտերը');
                return;
            }
        } else if (calcType === 'type3') {
            // Для холодильника: только цена за датчик
            formData.sensor_price = form.find('input[name="sensor_price"]').val();
            
            if (!formData.sensor_price) {
                alert('Խնդրում ենք լրացնել բոլոր դաշտերը');
                return;
            }
        }
        
        const submitButton = form.find('button[type="submit"]');
        
        $.ajax({
            url: machineCalculatorAdmin.ajax_url,
            type: 'POST',
            data: formData,
            beforeSend: function() {
                submitButton.prop('disabled', true).text('Պահպանում...');
            },
            success: function(response) {
                if (response.success) {
                    alert('Գինը հաջողությամբ պահպանված է!');
                    location.reload();
                } else {
                    alert('Սխալ: ' + response.data.message);
                }
            },
            error: function() {
                alert('Պահպանման ժամանակ սխալ է տեղի ունեցել');
            },
            complete: function() {
                submitButton.prop('disabled', false).text('Պահպանել կանոնը');
            }
        });
    });
    
    // Удаление правила
    $(document).on('click', '.delete-rule', function() {
        const ruleId = $(this).data('rule-id');
        const row = $(this).closest('tr');
        
        if (confirm('Համոզված եք, որ ցանկանում եք ջնջել այս կանոնը?')) {
            $.ajax({
                url: machineCalculatorAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_calculator_rule',
                    nonce: machineCalculatorAdmin.nonce,
                    rule_id: ruleId
                },
                beforeSend: function() {
                    row.css('opacity', '0.5');
                },
                success: function(response) {
                    if (response.success) {
                        row.fadeOut(function() {
                            $(this).remove();
                        });
                        alert('Կանոնը ջնջված է');
                    } else {
                        alert('Սխալ: ' + response.data.message);
                        row.css('opacity', '1');
                    }
                },
                error: function() {
                    alert('Ջնջման ժամանակ սխալ է տեղի ունեցել');
                    row.css('opacity', '1');
                }
            });
        }
    });
    
    // Vehicles Catalog AJAX handlers
    // Add Make/Model forms
    $('.sgc-ajax-form').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const action = form.data('action');
        const submitBtn = form.find('button[type="submit"]');
        const originalText = submitBtn.text();
        
        // Disable button and show loading
        submitBtn.prop('disabled', true).text('⏳');
        
        // Prepare form data
        const formData = {
            action: 'add_vehicle_' + action.replace('add_', ''),
            nonce: form.find('input[name="nonce"]').val() || machineCalculatorAdmin.sgc_nonce
        };
        
        // Add form fields
        form.find('input, select').each(function() {
            const field = $(this);
            if (field.attr('name') && field.attr('name') !== 'nonce') {
                formData[field.attr('name')] = field.val();
            }
        });
        
        console.log('Sending AJAX request:', formData);
        
        $.ajax({
            url: machineCalculatorAdmin.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('AJAX response:', response);
                
                if (response.success) {
                    // Show success message
                    showNotice(response.data.message, 'success');
                    
                    // Clear form
                    form[0].reset();
                    
                    // Reload data via AJAX instead of page reload
                    if (action === 'make') {
                        const type = form.find('input[name="type"]').val();
                        loadMakesData(type);
                    } else if (action === 'model') {
                        const makeId = form.find('input[name="make_id"]').val();
                        const activeMake = $('.sgc-make-link.active');
                        if (activeMake.length) {
                            const makeName = activeMake.data('make-name');
                            loadModelsData(makeId, makeName);
                        }
                    }
                } else {
                    // Show error message
                    showNotice(response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                showNotice('Ошибка при отправке запроса: ' + error, 'error');
            },
            complete: function() {
                // Re-enable button
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Delete buttons
    $('.sgc-ajax-delete').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const action = button.data('action');
        const id = button.data('id');
        const confirmText = button.data('confirm');
        
        if (!confirm(confirmText)) {
            return;
        }
        
        // Disable button and show loading
        button.prop('disabled', true).text('⏳');
        
        const formData = {
            action: 'delete_vehicle_' + action.replace('delete_', ''),
            nonce: $('input[name="nonce"]').val() || machineCalculatorAdmin.sgc_nonce
        };
        
        if (action === 'make') {
            formData.make_id = id;
        } else if (action === 'model') {
            formData.model_id = id;
        }
        
        console.log('Sending delete request:', formData);
        
        $.ajax({
            url: machineCalculatorAdmin.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Delete response:', response);
                
                if (response.success) {
                    // Show success message
                    showNotice(response.data.message, 'success');
                    
                    // Remove the item from the list
                    button.closest('.sgc-list-item').fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // If deleting a make, clear models list
                    if (action === 'make') {
                        $('.sgc-models-column .sgc-list').empty();
                        $('.sgc-models-column .sgc-list').append('<p class="sgc-empty">Выберите марку для просмотра моделей</p>');
                        $('.sgc-models-column .sgc-column-header h2').text('Մոդել');
                    }
                } else {
                    // Show error message
                    showNotice(response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Delete error:', error);
                showNotice('Ошибка при удалении: ' + error, 'error');
            },
            complete: function() {
                // Re-enable button
                button.prop('disabled', false).text('🗑️');
            }
        });
    });
    
    // Function to show notices
    function showNotice(message, type) {
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        // Remove existing notices
        $('.notice').remove();
        
        // Add new notice
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // Vehicles Catalog specific handlers
    // Check if catalog elements exist
    const catalogTabs = $('.sgc-type-tab');
    console.log('Found catalog tabs:', catalogTabs.length);
    
    if (catalogTabs.length > 0) {
        console.log('Setting up catalog handlers...');
    }
    
    // Use event delegation for dynamically loaded content
    $(document).on('click', '.sgc-type-tab', function(e) {
        e.preventDefault();
        
        console.log('Type tab clicked:', $(this).data('type'));
        
        const tab = $(this);
        const type = tab.data('type');
        
        // Update active tab
        $('.sgc-type-tab').removeClass('active');
        tab.addClass('active');
        
        // Update type in forms
        $('input[name="type"]').val(type);
        
        // Reload makes list
        loadMakesList(type);
    });
    
    // Make links
    $(document).on('click', '.sgc-make-link', function(e) {
        e.preventDefault();
        
        console.log('Make link clicked:', $(this).data('make-id'));
        
        const link = $(this);
        const makeId = link.data('make-id');
        const makeName = link.data('make-name');
        
        console.log('Make clicked:', { makeId: makeId, makeName: makeName });
        
        // Update active make
        $('.sgc-make-link').removeClass('active');
        link.addClass('active');
        
        // Update make_id in forms
        $('input[name="make_id"]').val(makeId);
        
        // Load models for this make
        if (makeId && makeName) {
            loadModelsList(makeId, makeName);
        }
    });
    
    // Search buttons
    $(document).on('click', '.sgc-search-btn', function(e) {
        e.preventDefault();
        
        console.log('Search button clicked');
        
        const btn = $(this);
        const target = btn.data('target');
        const input = btn.siblings('input[data-target="' + target + '"]');
        const query = input.val();
        
        if (target === 'makes') {
            loadMakesList(null, query);
        } else if (target === 'models') {
            const activeMake = $('.sgc-make-link.active');
            if (activeMake.length) {
                const makeId = activeMake.data('make-id');
                const makeName = activeMake.data('make-name');
                loadModelsList(makeId, makeName, query);
            }
        }
    });
    
    // Clear search buttons
    $(document).on('click', '.sgc-clear-search', function(e) {
        e.preventDefault();
        
        console.log('Clear search clicked');
        
        const btn = $(this);
        const target = btn.data('target');
        const input = btn.siblings('input[data-target="' + target + '"]');
        
        input.val('');
        
        if (target === 'makes') {
            loadMakesList();
        } else if (target === 'models') {
            const activeMake = $('.sgc-make-link.active');
            if (activeMake.length) {
                const makeId = activeMake.data('make-id');
                const makeName = activeMake.data('make-name');
                loadModelsList(makeId, makeName);
            }
        }
    });
    
    // Function to load makes list
    function loadMakesList(type = null, search = '') {
        const currentType = type || $('.sgc-type-tab.active').data('type');
        
        console.log('Loading makes list:', { type: currentType, search: search });
        
        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('type', currentType);
        if (search) {
            url.searchParams.set('qmake', search);
        } else {
            url.searchParams.delete('qmake');
        }
        url.searchParams.delete('make_id');
        url.searchParams.delete('qmodel');
        
        console.log('New URL:', url.toString());
        history.replaceState({}, '', url);
        
        // Load data via AJAX instead of page reload
        loadMakesData(currentType, search);
    }
    
    // Function to load models list
    function loadModelsList(makeId, makeName, search = '') {
        console.log('Loading models list:', { makeId: makeId, makeName: makeName, search: search });
        
        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('make_id', makeId);
        if (search) {
            url.searchParams.set('qmodel', search);
        } else {
            url.searchParams.delete('qmodel');
        }
        
        console.log('New URL:', url.toString());
        history.replaceState({}, '', url);
        
        // Load data via AJAX instead of page reload
        loadModelsData(makeId, makeName, search);
    }
    
    // Function to load makes data via AJAX
    function loadMakesData(type, search = '') {
        const formData = {
            action: 'get_vehicle_makes',
            nonce: machineCalculatorAdmin.sgc_nonce,
            type: type,
            search: search
        };
        
        console.log('Loading makes data via AJAX:', formData);
        
        $.ajax({
            url: machineCalculatorAdmin.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Makes data response:', response);
                if (response.success) {
                    updateMakesList(response.data.makes);
                    updateTypeInForms(type);
                    // Clear models list when switching types
                    $('.sgc-models-column .sgc-list').empty();
                    $('.sgc-models-column .sgc-list').append('<p class="sgc-empty">Выберите марку для просмотра моделей</p>');
                    $('.sgc-models-column .sgc-column-header h2').text('Մոդել');
                } else {
                    console.error('Error loading makes:', response.data.message);
                    showNotice('Ошибка загрузки марок: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error loading makes:', error);
                showNotice('Ошибка загрузки марок: ' + error, 'error');
            }
        });
    }
    
    // Function to load models data via AJAX
    function loadModelsData(makeId, makeName, search = '') {
        const formData = {
            action: 'get_vehicle_models',
            nonce: machineCalculatorAdmin.sgc_nonce,
            make_id: makeId,
            search: search
        };
        
        console.log('Loading models data via AJAX:', formData);
        
        $.ajax({
            url: machineCalculatorAdmin.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Models data response:', response);
                if (response.success) {
                    updateModelsList(response.data.models, makeName);
                } else {
                    console.error('Error loading models:', response.data.message);
                    showNotice('Ошибка загрузки моделей: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error loading models:', error);
                showNotice('Ошибка загрузки моделей: ' + error, 'error');
            }
        });
    }
    
    // Function to update makes list in DOM
    function updateMakesList(makes) {
        console.log('Updating makes list with:', makes);
        const makesList = $('.sgc-makes-column .sgc-list');
        makesList.empty();
        
        if (makes.length === 0) {
            makesList.append('<p class="sgc-empty">Марок пока нет</p>');
        } else {
            makes.forEach(function(make) {
                const makeItem = $('<li class="sgc-list-item">' +
                    '<a href="#" class="sgc-item-link sgc-make-link" ' +
                    'data-make-id="' + make.id + '" data-make-name="' + make.name + '">' +
                    make.name + '</a>' +
                    '<div class="sgc-item-actions">' +
                    '<button class="button button-small sgc-delete-btn sgc-ajax-delete" ' +
                    'data-action="delete_make" data-id="' + make.id + '" ' +
                    'data-confirm="Удалить марку и все её модели?">🗑️</button>' +
                    '</div>' +
                    '</li>');
                makesList.append(makeItem);
            });
        }
        
        console.log('Makes list updated, found items:', makesList.find('.sgc-list-item').length);
    }
    
    // Function to update models list in DOM
    function updateModelsList(models, makeName) {
        console.log('Updating models list with:', models, 'for make:', makeName);
        const modelsList = $('.sgc-models-column .sgc-list');
        modelsList.empty();
        
        if (models.length === 0) {
            modelsList.append('<p class="sgc-empty">Моделей пока нет</p>');
        } else {
            models.forEach(function(model) {
                const modelItem = $('<li class="sgc-list-item">' +
                    '<span class="sgc-item-name">' + model.name + '</span>' +
                    '<div class="sgc-item-actions">' +
                    '<button class="button button-small sgc-delete-btn sgc-ajax-delete" ' +
                    'data-action="delete_model" data-id="' + model.id + '" ' +
                    'data-confirm="Удалить модель?">🗑️</button>' +
                    '</div>' +
                    '</li>');
                modelsList.append(modelItem);
            });
        }
        
        // Update models header
        $('.sgc-models-column .sgc-column-header h2').text('Модели марки: ' + makeName);
        console.log('Models list updated, found items:', modelsList.find('.sgc-list-item').length);
    }
    
    // Function to update type in forms
    function updateTypeInForms(type) {
        $('input[name="type"]').val(type);
    }
    
    // Initialize catalog on page load
    function initializeCatalog() {
        console.log('Initializing catalog...');
        
        // Check if we're on the catalog tab
        if ($('.sgc-type-tab').length > 0) {
            console.log('Catalog elements found, initializing...');
            
            // Get current type from URL or default to mardatar
            const urlParams = new URLSearchParams(window.location.search);
            const currentType = urlParams.get('type') || 'mardatar';
            
            console.log('Current type from URL:', currentType);
            
            // Set active tab
            $('.sgc-type-tab').removeClass('active');
            $('.sgc-type-tab[data-type="' + currentType + '"]').addClass('active');
            
            // Update type in forms
            updateTypeInForms(currentType);
            
            // Load makes for current type
            loadMakesData(currentType);
        }
    }
    
    // Initialize catalog on page load
    initializeCatalog();
    
});
