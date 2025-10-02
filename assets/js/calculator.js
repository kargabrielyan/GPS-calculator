jQuery(function($) {
    const prices = machine_calc_ajax.prices || { type1_in_am:4000, type1_out_am:4000, type2_tank:1000, type3_ref:500 };
    const restBase = machine_calc_ajax.rest_base || '/wp-json/mc/v1';
    const vehiclesDynamic = machine_calc_ajax.vehicles_dynamic || false;
    const i18n = machine_calc_ajax.i18n || {};

    console.log('Machine Calculator: Ավտոմեքենաների դինամիկ տվյալներ:', vehiclesDynamic);
    console.log('Machine Calculator: REST հիմնական URL:', restBase);

    // Initialize modern radio buttons
    function initModernRadioButtons() {
        // Поддержка для браузеров без :has() селектора
        $('.mc-options input[type="radio"]').on('change', function() {
            const $parent = $(this).closest('.mc-options');
            $parent.find('label').removeClass('mc-checked');
            $(this).closest('label').addClass('mc-checked');
        });

        $('.mc-options input[type="radio"]').on('focus', function() {
            $(this).closest('label').addClass('mc-focused');
        });

        $('.mc-options input[type="radio"]').on('blur', function() {
            $(this).closest('label').removeClass('mc-focused');
        });

        // Инициализация состояния checked при загрузке
        $('.mc-options input[type="radio"]:checked').each(function() {
            $(this).closest('label').addClass('mc-checked');
        });
    }

    // Cache for loaded data
    let makesCache = null;
    let modelsCache = {};
    let yearsCache = {};

    // Tabs
    $(document).on('click', '.mc-tab', function() {
        const tabId = $(this).data('tab');
        $('.mc-tab').removeClass('active');
        $(this).addClass('active');
        $('.mc-panel').removeClass('active');
        $('#' + tabId).addClass('active');
        updateTotal();
    });

    // Vehicle type change handlers
    $(document).on('change', '#mc1-vehicle-type, #mc2-vehicle-type', function() {
        const vehicleType = $(this).val();
        const tabPrefix = $(this).attr('id').split('-')[0]; // mc1 or mc2
        const makeSelect = $(`#${tabPrefix}-make`);
        const modelSelect = $(`#${tabPrefix}-model`);
        
        if (vehicleType) {
            makeSelect.prop('disabled', false);
            makeSelect.empty().append(`<option value="">${i18n.loading_makes || 'Մակնիշների բեռնում...'}</option>`);
            
            loadMakes(vehicleType).then(makes => {
                makeSelect.empty();
                makeSelect.append(`<option value="">${i18n.select_make || 'Ընտրել'}</option>`);
                
                makes.forEach(make => {
                    makeSelect.append(`<option value="${make.id}">${make.name}</option>`);
                });
                
                makeSelect.prop('disabled', false);
            }).catch(error => {
                console.error('Machine Calculator: Ошибка загрузки марок для типа', vehicleType, ':', error);
                makeSelect.empty().append(`<option value="">${i18n.error_loading_data || 'Տվյալների բեռնման սխալ'}</option>`);
            });
        } else {
            makeSelect.prop('disabled', true).empty().append(`<option value="">${i18n.select_type_first || 'Նախ ընտրեք մեքենայի տիպը'}</option>`);
            modelSelect.prop('disabled', true).empty();
        }
        
        // Clear dependent selects
        modelSelect.prop('disabled', true).empty();
        updateTotal();
    });

    // Dynamic vehicles data functions
    function loadMakes(vehicleType = 'mardatar') {
        const cacheKey = 'makes_' + vehicleType;
        if (makesCache && makesCache[cacheKey]) {
            return Promise.resolve(makesCache[cacheKey]);
        }

        return fetch(`${restBase}/makes?vehicle_type=${vehicleType}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.data) {
                    if (!makesCache) makesCache = {};
                    makesCache[cacheKey] = data.data;
                    console.log('Machine Calculator: Բեռնված մակնիշներ для типа', vehicleType, ':', data.data.length);
                    return data.data;
                }
                throw new Error('Invalid response format');
            })
            .catch(error => {
                console.error('Machine Calculator: Մակնիշների բեռնման սխալ:', error);
                showMessage(i18n.error_loading_data || 'Տվյալների բեռնման սխալ', 'error');
                return getFallbackMakes(vehicleType);
            });
    }

    function loadModels(make) {
        if (!make) return Promise.resolve([]);
        
        const cacheKey = make;
        if (modelsCache[cacheKey]) {
            return Promise.resolve(modelsCache[cacheKey]);
        }

        return fetch(`${restBase}/models?make=${encodeURIComponent(make)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.data) {
                    modelsCache[cacheKey] = data.data;
                    console.log(`Machine Calculator: Բեռնված մոդելներ ${make}-ի համար:`, data.data.length);
                    return data.data;
                }
                throw new Error('Invalid response format');
            })
            .catch(error => {
                console.error(`Machine Calculator: Մոդելների բեռնման սխալ ${make}-ի համար:`, error);
                showMessage(i18n.error_loading_data || 'Տվյալների բեռնման սխալ', 'error');
                return getFallbackModels(make);
            });
    }

    function loadYears(make, model) {
        if (!make || !model) return Promise.resolve([]);
        
        const cacheKey = `${make}_${model}`;
        if (yearsCache[cacheKey]) {
            return Promise.resolve(yearsCache[cacheKey]);
        }

        return fetch(`${restBase}/years?make=${encodeURIComponent(make)}&model=${encodeURIComponent(model)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.data) {
                    yearsCache[cacheKey] = data.data;
                    console.log(`Machine Calculator: Բեռնված տարիներ ${make} ${model}-ի համար:`, data.data.length);
                    return data.data;
                }
                throw new Error('Invalid response format');
            })
            .catch(error => {
                console.error(`Machine Calculator: Տարիների բեռնման սխալ ${make} ${model}-ի համար:`, error);
                return getFallbackYears();
            });
    }

    // Utility function to populate select
    function populateSelect($select, items, placeholder) {
        $select.empty();
        $select.append(`<option value="">${placeholder}</option>`);
        
        items.forEach(item => {
            $select.append(`<option value="${item.id}">${item.name}</option>`);
        });
    }

    // Fallback data functions
    function getFallbackMakes() {
        return [
            {id: 'Toyota', name: 'Toyota'},
            {id: 'Mercedes', name: 'Mercedes'},
            {id: 'BMW', name: 'BMW'},
            {id: 'Audi', name: 'Audi'},
            {id: 'Volkswagen', name: 'Volkswagen'},
            {id: 'Hyundai', name: 'Hyundai'},
            {id: 'Kia', name: 'Kia'},
            {id: 'Nissan', name: 'Nissan'},
            {id: 'Ford', name: 'Ford'},
            {id: 'Lexus', name: 'Lexus'}
        ];
    }

    function getFallbackModels(make) {
        const fallbackModels = {
            'Toyota': ['Corolla', 'Camry', 'RAV4', 'Prius', 'Highlander', 'Sienna', 'Avalon', '4Runner', 'Tacoma', 'Tundra', 'Sequoia', 'Land Cruiser', 'Yaris', 'C-HR', 'Venza', 'Mirai', 'GR Supra', 'GR86'],
            'Mercedes': ['C-Class', 'E-Class', 'GLC', 'GLE', 'A-Class', 'S-Class'],
            'BMW': ['3 Series', '5 Series', 'X5', 'X3', '7 Series', 'X1'],
            'Audi': ['A4', 'Q5', 'A6', 'Q7', 'A3', 'Q3'],
            'Volkswagen': ['Golf', 'Passat', 'Tiguan', 'Jetta', 'Atlas', 'Touareg'],
            'Hyundai': ['Elantra', 'Tucson', 'Santa Fe', 'Sonata', 'Kona', 'Palisade'],
            'Kia': ['Sportage', 'Rio', 'Sorento', 'Optima', 'Soul', 'Telluride'],
            'Nissan': ['Qashqai', 'X-Trail', 'Altima', 'Sentra', 'Murano', 'Pathfinder'],
            'Ford': ['Focus', 'Mondeo', 'Explorer', 'Mustang', 'F-150', 'Escape'],
            'Lexus': ['RX', 'NX', 'ES', 'GX', 'LS', 'UX']
        };
        
        const models = fallbackModels[make] || [];
        return models.map(model => ({id: model, name: model}));
    }

    function getFallbackYears() {
        const currentYear = new Date().getFullYear();
        const maxYear = Math.min(currentYear, 2027);
        const minYear = 1900;
        const years = [];
        
        for (let year = maxYear; year >= minYear; year--) {
            years.push(year);
        }
        return years;
    }

    // Initialize brand selects
    function initializeMakes() {
        const $makeSelects = $('#mc1-make, #mc2-make');
        
        $makeSelects.each(function() {
            const $select = $(this);
            $select.empty().append(`<option value="">${i18n.select_type_first || 'Նախ ընտրեք մեքենայի տիպը'}</option>`);
            $select.prop('disabled', true);
        });
    }

    // Handle make change
    function handleMakeChange(makeSelector, modelSelector) {
        const make = $(makeSelector).val();
        const $modelSelect = $(modelSelector);
        
        // Reset model select
        $modelSelect.empty().append(`<option value="">${i18n.select_model || 'Ընտրել'}</option>`);
        $modelSelect.prop('disabled', true);
        
        if (!make) {
            return;
        }
        
        // Show loading
        $modelSelect.empty().append(`<option value="">${i18n.loading_models || 'Մոդելների բեռնում...'}</option>`);
        
        loadModels(make).then(models => {
            $modelSelect.empty();
            $modelSelect.append(`<option value="">${i18n.select_model || 'Ընտրել'}</option>`);
            
            models.forEach(model => {
                $modelSelect.append(`<option value="${model.id}">${model.name}</option>`);
            });
            
            $modelSelect.prop('disabled', false);
        });
    }

    // Event handlers for make/model changes
    $(document).on('change', '#mc1-make', function() {
        handleMakeChange('#mc1-make', '#mc1-model');
        updateTotal();
    });

    $(document).on('change', '#mc2-make', function() {
        handleMakeChange('#mc2-make', '#mc2-model');
        updateTotal();
    });

    // Inputs affecting totals
    $(document).on('input change', '#mc1-cars, #mc2-cars, #mc2-tanks, #mc3-refs, #mc3-sensors, #mc1-loc-in, #mc1-loc-out, #mc1-year, #mc2-year', updateTotal);
    $(document).on('change', '#mc1-model, #mc2-model', updateTotal);

    // Year validation in real-time
    $(document).on('input', '#mc1-year, #mc2-year', function() {
        validateYearField($(this));
    });

    function validateYearField($field) {
        let value = $field.val();
        
        // Remove non-numeric characters
        value = value.replace(/[^0-9]/g, '');
        
        // Limit to 4 digits
        if (value.length > 4) {
            value = value.substring(0, 4);
        }
        
        // Apply the cleaned value
        if ($field.val() !== value) {
            $field.val(value);
        }
        
        // Validate range if 4 digits entered
        if (value.length === 4) {
            const year = parseInt(value);
            if (year < 1900) {
                $field.val('1900');
                showMessage('Նվազագույն տարի: 1900', 'warning');
            } else if (year > 2027) {
                $field.val('2027');
                showMessage('Առավելագույն տարի: 2027', 'warning');
            }
        }
    }

    function activeType() {
        if ($('#mc-tab-1').hasClass('active')) return 'type1';
        if ($('#mc-tab-2').hasClass('active')) return 'type2';
        return 'type3';
    }

    function countForType() {
        const t = activeType();
        if (t === 'type1') return Math.max(1, parseInt($('#mc1-cars').val() || '1', 10));
        if (t === 'type2') return Math.max(1, parseInt($('#mc2-cars').val() || '1', 10));
        // For type3 (refrigerator), return sensor count
        return Math.max(1, parseInt($('#mc3-sensors').val() || '1', 10));
    }

    function updateTotal() {
        const t = activeType();
        
        // Простая логика расчёта без AJAX запросов к базе правил
        let total = 0;
        
        if (t === 'type1') {
            // Цена за зону, умножается на количество машин
            const pricePerMachine = $('#mc1-loc-out').is(':checked') ? (prices.type1_out_am||4000) : (prices.type1_in_am||4000);
            const machineCount = Math.max(1, parseInt($('#mc1-cars').val() || '1', 10));
            total = machineCount * pricePerMachine;
            console.log('Machine Calculator Type1: մեքենաներ =', machineCount, ', գին մեկ մեքենայի համար =', pricePerMachine + '֏', ', ընդամենը =', total + '֏');
        } else if (t === 'type2') {
            // Цена за бак, умножается на количество баков
            const pricePerTank = prices.type2_tank || 1000; // цена за один бак
            const tankCount = Math.max(0, parseInt($('#mc2-tanks').val() || '0', 10));
            total = tankCount * pricePerTank;
            console.log('Machine Calculator Type2: բաքեր =', tankCount, ', գին մեկ բաքի համար =', pricePerTank + '֏', ', ընդամենը =', total + '֏');
        } else { // type3
            // Цена за датчик, умножается на количество датчиков
            const pricePerSensor = prices.type3_ref || 500; // цена за один датчик
            const sensorCount = Math.max(1, parseInt($('#mc3-sensors').val() || '1', 10));
            total = sensorCount * pricePerSensor;
            console.log('Machine Calculator Type3: սենսորներ =', sensorCount, ', գին մեկ սենսորի համար =', pricePerSensor + '֏', ', ընդամենը =', total + '֏');
        }

        const currencySymbol = i18n.currency_symbol || '֏';
        $('#mc-total-price').text(Math.round(total) + ' ' + currencySymbol);
        $('#create-order-btn').prop('disabled', total <= 0);
    }

    function showMessage(text, type) {
        const $m = $('#calculator-message');
        $m.removeClass('success error info warning').addClass(type).text(text).show();
        if (type === 'success') setTimeout(() => $m.fadeOut(), 2500);
    }

    function resetBtn() {
        $('#create-order-btn').prop('disabled', false).text(i18n.order_button || 'Ձևակերպել պատվեր');
    }

    // Validation function
    function validateForm() {
        const t = activeType();
        const errors = [];

        if (t === 'type1') {
            // Validation for Type 1 (Vehicle Movement Monitoring)
            const make = $('#mc1-make').val();
            const model = $('#mc1-model').val();
            const year = $('#mc1-year').val();
            const cars = $('#mc1-cars').val();

            if (!make || make === '') {
                errors.push('Ընտրեք ավտոմեքենայի մակնիշը');
            }
            if (!model || model === '') {
                errors.push('Ընտրեք ավտոմեքենայի մոդելը');
            }
            if (!year || year === '') {
                errors.push('Նշեք ավտոմեքենայի արտադրության տարին');
            } else {
                const yearNum = parseInt(year);
                if (yearNum < 1900 || yearNum > 2027) {
                    errors.push('Արտադրության տարին պետք է լինի 1900-2027 թվականների միջև');
                }
            }
            if (!cars || parseInt(cars) < 1) {
                errors.push('Ավտոմեքենաների քանակը պետք է լինի առնվազն 1');
            }
        } else if (t === 'type2') {
            // Validation for Type 2 (Fuel Monitoring)
            const make = $('#mc2-make').val();
            const model = $('#mc2-model').val();
            const year = $('#mc2-year').val();
            const cars = $('#mc2-cars').val();
            const tanks = $('#mc2-tanks').val();

            if (!make || make === '') {
                errors.push('Ընտրեք ավտոմեքենայի մակնիշը');
            }
            if (!model || model === '') {
                errors.push('Ընտրեք ավտոմեքենայի մոդելը');
            }
            if (!year || year === '') {
                errors.push('Նշեք ավտոմեքենայի արտադրության տարին');
            } else {
                const yearNum = parseInt(year);
                if (yearNum < 1900 || yearNum > 2027) {
                    errors.push('Արտադրության տարին պետք է լինի 1900-2027 թվականների միջև');
                }
            }
            if (!cars || parseInt(cars) < 1) {
                errors.push('Ավտոմեքենաների քանակը պետք է լինի առնվազն 1');
            }
            if (tanks === '' || parseInt(tanks) < 0) {
                errors.push('Նշեք վառելիքի բաքերի քանակը (0 կամ ավելի)');
            }
        } else if (t === 'type3') {
            // Validation for Type 3 (Refrigerator Temperature Monitoring)
            const refs = $('#mc3-refs').val();
            const sensors = $('#mc3-sensors').val();

            if (!refs || parseInt(refs) < 1) {
                errors.push('Սառնարանների քանակը պետք է լինի առնվազն 1');
            }
            if (!sensors || parseInt(sensors) < 1) {
                errors.push('Սենսորների քանակը պետք է լինի առնվազն 1');
            }
        }

        return errors;
    }

    // Create order
    $(document).on('click', '#create-order-btn', function(e) {
        e.preventDefault();
        
        // Validate form first
        const validationErrors = validateForm();
        if (validationErrors.length > 0) {
            showMessage('Լրացման սխալներ:\n• ' + validationErrors.join('\n• '), 'error');
            return;
        }
        
        const t = activeType();
        const data = { action: 'create_machine_order', nonce: machine_calc_ajax.nonce, calc_type: t };

        if (t === 'type1') {
            data.location = $('#mc1-loc-out').is(':checked') ? 'out_am' : 'in_am';
            data.car_make = ($('#mc1-make').val() || '').trim();
            data.car_model = ($('#mc1-model').val() || '').trim();
            data.car_year = $('#mc1-year').val() || '';
            data.cars_count = Math.max(1, parseInt($('#mc1-cars').val() || '1', 10));
        } else if (t === 'type2') {
            data.car_make_2 = ($('#mc2-make').val() || '').trim();
            data.car_model_2 = ($('#mc2-model').val() || '').trim();
            data.car_year_2 = $('#mc2-year').val() || '';
            data.cars_count_2 = Math.max(1, parseInt($('#mc2-cars').val() || '1', 10));
            data.tanks_count = Math.max(0, parseInt($('#mc2-tanks').val() || '0', 10));
        } else {
            data.refs_count = Math.max(1, parseInt($('#mc3-refs').val() || '1', 10));
            data.sensors_count = Math.max(1, parseInt($('#mc3-sensors').val() || '1', 10));
        }

        $('#create-order-btn').prop('disabled', true).text(i18n.order_creating || 'Պատվերի ստեղծում...');
        showMessage(i18n.order_creating || 'Անհատական ապրանքի ստեղծում...', 'info');

        $.ajax({
            url: machine_calc_ajax.ajax_url,
            type: 'POST',
            data,
            timeout: 30000,
            success: function(resp){
                if (resp && resp.success && resp.data && resp.data.redirect_url) {
                    showMessage('Ապրանքը ստեղծված է! Ուղղորդում...', 'success');
                    setTimeout(() => window.location.href = resp.data.redirect_url, 900);
                } else {
                    showMessage('Սխալ: ' + (resp && resp.data ? resp.data : 'Անհայտ'), 'error');
                    resetBtn();
                }
            },
            error: function(xhr, status){
                const msg = status === 'timeout' ? 'Սպասման ժամանակը գերազանցվել է' : (xhr.status === 500 ? 'Սերվերի ներքին սխալ' : 'Պատվերի ստեղծման սխալ');
                showMessage(msg, 'error');
                resetBtn();
            }
        });
    });

    // Initialize everything
    // Initialize components
    initModernRadioButtons();
    
    // Always use dynamic data loading through REST API
    console.log('Machine Calculator: Ավտոմեքենաների դինամիկ տվյալների նախաձեռնում');
    initializeMakes();
    
    updateTotal();
});
