/**
 * JavaScript для автозаполнения комментариев в checkout
 * на основе параметров калькулятора
 */

jQuery(document).ready(function($) {
    
    // Проверяем, что мы на странице checkout
    if (!$('body').hasClass('woocommerce-checkout')) {
        return;
    }
    
    // Ищем поле комментариев (может быть разные селекторы в зависимости от темы)
    var commentSelectors = [
        '#order_comments',
        'textarea[name="order_comments"]',
        '.woocommerce-additional-fields textarea',
        'textarea[placeholder*="комментар"]',
        'textarea[placeholder*="заметк"]',
        'textarea[placeholder*="примечан"]'
    ];
    
    var $commentField = null;
    
    // Пробуем найти поле комментариев
    for (var i = 0; i < commentSelectors.length; i++) {
        $commentField = $(commentSelectors[i]);
        if ($commentField.length > 0) {
            break;
        }
    }
    
    if (!$commentField || $commentField.length === 0) {
        console.log('Machine Calculator: Մեկնաբանությունների դաշտը չի գտնվել');
        return;
    }
    
    console.log('Machine Calculator: Գտնված է մեկնաբանությունների դաշտը:', $commentField);
    
    // Загружаем комментарий из калькулятора
    loadCalculatorComment();
    
    /**
     * Загружает комментарий с параметрами калькулятора
     */
    function loadCalculatorComment() {
        $.ajax({
            url: machineCalculatorCheckout.ajax_url,
            type: 'POST',
            data: {
                action: 'get_calculator_checkout_comment',
                nonce: machineCalculatorCheckout.nonce
            },
            success: function(response) {
                if (response.success && response.data.comment) {
                    insertCalculatorComment(response.data.comment);
                } else {
                    console.log('Machine Calculator: Հաշվիչի մեկնաբանությունը չի գտնվել');
                }
            },
            error: function(xhr, status, error) {
                console.log('Machine Calculator: Մեկնաբանության բեռնման սխալ:', error);
            }
        });
    }
    
    /**
     * Вставляет комментарий калькулятора в поле
     */
    function insertCalculatorComment(calculatorComment) {
        var currentComment = $commentField.val().trim();
        var newComment = '';
        
        if (currentComment) {
            // Если уже есть комментарий, добавляем параметры калькулятора сверху
            newComment = calculatorComment + '\n\n' + currentComment;
        } else {
            // Если поля пустое, просто вставляем параметры калькулятора
            newComment = calculatorComment;
        }
        
        $commentField.val(newComment);
        
        // Делаем поле слегка заметным (подсветка)
        $commentField.css('background-color', '#f0f8ff');
        setTimeout(function() {
            $commentField.css('background-color', '');
        }, 2000);
        
        console.log('Machine Calculator: Հաշվիչի մեկնաբանությունը ավելացվել է checkout-ում');
        
        // Опционально: фокусируемся на поле и прокручиваем к нему
        if (shouldScrollToComment()) {
            scrollToCommentField();
        }
    }
    
    /**
     * Проверяет, нужно ли прокручивать к полю комментариев
     */
    function shouldScrollToComment() {
        // Прокручиваем только если поле не видно на экране
        var fieldTop = $commentField.offset().top;
        var windowTop = $(window).scrollTop();
        var windowBottom = windowTop + $(window).height();
        
        return fieldTop < windowTop || fieldTop > windowBottom;
    }
    
    /**
     * Плавно прокручивает к полю комментариев
     */
    function scrollToCommentField() {
        $('html, body').animate({
            scrollTop: $commentField.offset().top - 100
        }, 1000);
    }
    
    // Дополнительно: отслеживаем обновления checkout (для AJAX checkout)
    $(document.body).on('updated_checkout', function() {
        // Перезапускаем поиск поля комментариев после обновления checkout
        setTimeout(function() {
            // Проверяем, не потерялось ли поле после AJAX обновления
            if ($commentField.length === 0 || !$commentField.is(':visible')) {
                // Ищем поле заново
                for (var i = 0; i < commentSelectors.length; i++) {
                    $commentField = $(commentSelectors[i]);
                    if ($commentField.length > 0) {
                        break;
                    }
                }
            }
        }, 500);
    });
    
    // Дебаг: показываем информацию о найденном поле
    if (window.console && console.log) {
        console.log('Machine Calculator Checkout Script loaded');
        console.log('Comment field found:', $commentField.length > 0);
        if ($commentField.length > 0) {
            console.log('Comment field selector:', $commentField[0].tagName + 
                       ($commentField[0].id ? '#' + $commentField[0].id : '') +
                       ($commentField[0].className ? '.' + $commentField[0].className.split(' ').join('.') : ''));
        }
    }
});

/**
 * Дополнительная функция для ручного запуска (для отладки)
 */
window.machineCalculatorDebug = {
    loadComment: function() {
        jQuery.ajax({
            url: machineCalculatorCheckout.ajax_url,
            type: 'POST',
            data: {
                action: 'get_calculator_checkout_comment',
                nonce: machineCalculatorCheckout.nonce
            },
            success: function(response) {
                console.log('Debug response:', response);
            }
        });
    },
    
    findCommentField: function() {
        var selectors = [
            '#order_comments',
            'textarea[name="order_comments"]',
            '.woocommerce-additional-fields textarea',
            'textarea[placeholder*="комментар"]',
            'textarea[placeholder*="заметк"]',
            'textarea[placeholder*="примечан"]'
        ];
        
        selectors.forEach(function(selector) {
            var field = jQuery(selector);
            if (field.length > 0) {
                console.log('Found field with selector:', selector, field);
            }
        });
    }
};
