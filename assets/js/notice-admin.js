/**
 * Machine Calculator Notice Admin JS
 * 
 * Handles live preview and form interactions in admin.
 */

jQuery(document).ready(function($) {
    
    // Live preview functionality
    function updatePreview() {
        const enabled = $('#enabled').is(':checked');
        const variant = $('#variant').val();
        const position = $('#position').val();
        const globalContent = $('#global').val();
        
        const $container = $('#mc-notice-preview-container');
        
        if (!enabled || !globalContent.trim()) {
            $container.empty();
            return;
        }
        
        const iconSvg = getIconSvg(variant);
        const classes = `mc-notice mc-notice--${variant} mc-notice--${position}`;
        
        const previewHtml = `
            <div class="${classes}" role="note">
                <div class="mc-notice__icon" aria-hidden="true">
                    ${iconSvg}
                </div>
                <div class="mc-notice__content">
                    ${globalContent}
                </div>
            </div>
        `;
        
        $container.html(previewHtml);
    }
    
    // Get SVG icon for variant
    function getIconSvg(variant) {
        const icons = {
            'info': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="m12 16 0-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="m12 8 .01 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            'warning': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m12 2 10 18H2L12 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m12 9 0 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="m12 17 .01 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            'success': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'accent': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        };
        
        return icons[variant] || icons['accent'];
    }
    
    // Event handlers
    $('#enabled, #variant, #position').on('change', updatePreview);
    
    // Handle WYSIWYG editor changes
    if (typeof tinymce !== 'undefined') {
        $(document).on('tinymce-editor-init', function(event, editor) {
            if (editor.id === 'global') {
                editor.on('input change', function() {
                    setTimeout(updatePreview, 100);
                });
            }
        });
    }
    
    // Fallback for textarea mode
    $('#global').on('input', function() {
        setTimeout(updatePreview, 100);
    });
    
    // Initial preview
    setTimeout(updatePreview, 500);
    
    // Form validation
    $('form').on('submit', function(e) {
        const enabled = $('#enabled').is(':checked');
        const globalContent = $('#global').val();
        const type1Content = $('#type1').val();
        const type2Content = $('#type2').val();  
        const type3Content = $('#type3').val();
        
        if (enabled && !globalContent.trim() && !type1Content.trim() && !type2Content.trim() && !type3Content.trim()) {
            e.preventDefault();
            alert('Пожалуйста, заполните хотя бы один комментарий (глобальный или для конкретного таба).');
            return false;
        }
    });
    
    // Toggle override sections based on global content
    function toggleOverrideSections() {
        const hasGlobal = $('#global').val().trim().length > 0;
        const $overrideSection = $('.mc-override-explanation');
        
        if (hasGlobal) {
            $overrideSection.show();
        } else {
            $overrideSection.hide();
        }
    }
    
    $('#global').on('input', toggleOverrideSections);
    toggleOverrideSections();
    
    // Auto-save functionality (optional)
    let saveTimeout;
    function autoSave() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function() {
            if (typeof mcNoticeAdmin !== 'undefined') {
                // Implementation for auto-save can be added here
                console.log('Auto-save triggered');
            }
        }, 2000);
    }
    
    $('input, select, textarea').on('change input', autoSave);
});
