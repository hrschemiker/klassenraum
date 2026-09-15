<?php
if (!defined('ABSPATH')) exit;

final class GTBP_Visual_Unifier {
    private static $instance = null;
    public static function instance(){ return self::$instance ?: (self::$instance = new self()); }
    private function __construct(){
        add_action('wp_footer', [$this,'print_frontend_overrides'], 999);
        add_action('admin_head', [$this,'print_admin_overrides'], 999);
    }
    private function should_print(){
        if (is_admin()) return true;
        global $post;
        if (!is_a($post,'WP_Post')) return false;
        return has_shortcode($post->post_content,'german_teacher_booking') || has_shortcode($post->post_content,'german_student_panel') || has_shortcode($post->post_content,'german_session');
    }
    public function print_admin_overrides(){
        // Fix #15: use wp_add_inline_style instead of raw echo to stay in the asset pipeline.
        wp_register_style('gtbp-visual-unifier-admin', false);
        wp_enqueue_style('gtbp-visual-unifier-admin');
        wp_add_inline_style('gtbp-visual-unifier-admin', $this->css());
    }
    public function print_frontend_overrides(){
        if (!$this->should_print()) return;
        wp_register_style('gtbp-visual-unifier', false);
        wp_enqueue_style('gtbp-visual-unifier');
        wp_add_inline_style('gtbp-visual-unifier', $this->css());
    }
    private function css(){ return <<<'CSS'
.gtbp-wrapper,.gtbp-wrapper *{box-sizing:border-box!important;font-family:IRANSansXFaNum,Tahoma,sans-serif!important}.gtbp-wrapper{max-width:100%!important;width:100%!important;margin:0!important;border:1px solid #e8edf5!important;border-radius:22px!important;box-shadow:0 16px 42px rgba(15,23,42,.08)!important;background:#fff!important;color:#111!important;padding:18px!important;overflow:hidden!important}.gtbp-wrapper:before{content:"";display:block;height:4px;margin:-18px -18px 18px;background:linear-gradient(90deg,#111 0 33%,#dd0000 33% 66%,#ffce00 66% 100%)}.gtbp-step-title,.gtbp-wrapper h3,.gtbp-wrapper h4{color:#111!important;font-weight:900!important;letter-spacing:-.01em!important}.gtbp-step-title{border-bottom:1.5px solid #edf1f7!important;padding-bottom:12px!important;margin-bottom:16px!important}.gtbp-wrapper input,.gtbp-wrapper select,.gtbp-wrapper textarea{border:1px solid #dbe3ef!important;border-radius:14px!important;box-shadow:none!important;background:#fff!important;min-height:44px!important}.gtbp-wrapper input:focus,.gtbp-wrapper select:focus,.gtbp-wrapper textarea:focus{outline:none!important;border-color:#dd0000!important;box-shadow:0 0 0 4px rgba(221,0,0,.10)!important}.gtbp-wrapper button,.gtbp-wrapper .gtbp-submit-btn,.gtbp-wrapper .gtbp-payment-btn,.gtbp-wrapper .gtbp-btn-primary{border:0!important;border-radius:14px!important;background:linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;color:#fff!important;box-shadow:0 10px 24px rgba(221,0,0,.18)!important;font-weight:900!important;transition:transform .16s ease,box-shadow .16s ease,filter .16s ease!important}.gtbp-wrapper button:hover,.gtbp-wrapper .gtbp-submit-btn:hover,.gtbp-wrapper .gtbp-payment-btn:hover,.gtbp-wrapper .gtbp-btn-primary:hover{transform:translateY(-1px)!important;filter:saturate(1.05)!important;box-shadow:0 14px 30px rgba(221,0,0,.26)!important}.gtbp-wrapper .gtbp-date-box,.gtbp-wrapper .gtbp-slot-btn,.gtbp-wrapper .gtbp-class-card,.gtbp-wrapper .gtbp-cart-item{border:1px solid #e7edf6!important;border-radius:16px!important;background:#fff!important;box-shadow:0 8px 20px rgba(15,23,42,.045)!important}.gtbp-wrapper .gtbp-date-box:hover:not(.disabled),.gtbp-wrapper .gtbp-slot-btn:hover{border-color:#dd0000!important;background:#fff6f6!important}.gtbp-wrapper .selected,.gtbp-wrapper .gtbp-date-box.selected,.gtbp-wrapper .gtbp-slot-btn.selected{border-color:#dd0000!important;background:#fff5f5!important;color:#a90000!important}.gtbp-wrapper .disabled{opacity:.45!important;filter:grayscale(.35)!important}.gtbp-wrapper table{max-width:100%!important}.gtbp-wrapper img,.gtbp-wrapper iframe{max-width:100%!important}.gls-booking-portal .gtbp-wrapper{border-radius:18px!important;padding:14px!important}.gls-booking-portal .gtbp-wrapper:before{margin:-14px -14px 14px}@media(max-width:800px){.gtbp-wrapper{border-radius:18px!important;padding:14px!important}.gtbp-wrapper:before{margin:-14px -14px 14px}.gtbp-wrapper button,.gtbp-wrapper .gtbp-submit-btn,.gtbp-wrapper .gtbp-payment-btn{min-height:44px!important}}

/* active date-box must punch through the generic white-background override */
.gtbp-wrapper .gtbp-date-box.active{background:#dd0000!important;color:#fff!important;border-color:#dd0000!important;box-shadow:0 8px 20px rgba(221,0,0,.22)!important;}
.gtbp-wrapper .gtbp-date-box.active .gtbp-j-num,.gtbp-wrapper .gtbp-date-box.active .gtbp-g-num{color:#fff!important;opacity:1!important;}
/* restore night slot background after visual unifier generic slot cards */
.gtbp-wrapper .gtbp-slot-btn.slot-night:not(.booked):not(.active){background:#172554!important;color:#fff!important;border-color:#1e3a8a!important;box-shadow:0 4px 14px rgba(23,37,84,.22)!important;}
.gtbp-wrapper .gtbp-slot-btn.slot-night:not(.booked):not(.active):hover{background:#1e3a8a!important;color:#fff!important;border-color:#60a5fa!important;}
.gtbp-wrapper .gtbp-slot-btn.slot-night .gtbp-slot-icon{background:#243b73!important;color:#fff!important;border-color:rgba(255,255,255,.35)!important;}
CSS; }
}
