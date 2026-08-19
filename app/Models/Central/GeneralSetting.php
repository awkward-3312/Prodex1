<?php

namespace App\Models\Central;

use App\Support\LandingPageTemplate;
use Illuminate\Database\Eloquent\Model;

class GeneralSetting extends Model
{
    protected $connection = 'central';
    protected $table = 'general_settings';

    protected $fillable = ['app_name','currency_code','currency_symbol','company_name','phone','email','address','website','logo_path','favicon_path','hosting_mode','landing_template','landing_font','landing_heading_font','landing_custom_font_name','landing_custom_font_path','bank_details','show_customizer_button','show_site_name','dashboard_footer_text','tenant_app_name','tenant_company_name','tenant_email','tenant_phone','tenant_address','tenant_logo_path','tenant_favicon_path','tenant_currency_code','tenant_currency_symbol','tenant_default_language','tenant_footer_text','tenant_page_title_suffix','tenant_developed_by','reserved_subdomains','subscription_reminders_enabled','subscription_reminder_offsets','subscription_reminder_channels','trial_reminders_enabled','trial_reminder_offsets','subscription_banner_threshold_days','sms_gateway','subscription_reminder_sms','trial_reminder_sms','demo_data_enabled','whatsapp_enabled','whatsapp_provider','whatsapp_default_templates'];
    protected $casts = ['bank_details'=>'array','reserved_subdomains'=>'array','show_customizer_button'=>'boolean','show_site_name'=>'boolean','subscription_reminders_enabled'=>'boolean','subscription_reminder_offsets'=>'array','subscription_reminder_channels'=>'array','trial_reminders_enabled'=>'boolean','trial_reminder_offsets'=>'array','subscription_banner_threshold_days'=>'integer','demo_data_enabled'=>'boolean','whatsapp_enabled'=>'boolean','whatsapp_default_templates'=>'array'];

    public const LANDING_FONTS = ['Inter'=>'Inter','Open Sans'=>'Open Sans','Montserrat'=>'Montserrat','Poppins'=>'Poppins','Nunito'=>'Nunito','Nunito Sans'=>'Nunito Sans','Work Sans'=>'Work Sans','Manrope'=>'Manrope','Sora'=>'Sora','DM Sans'=>'DM Sans','Raleway'=>'Raleway','Mulish'=>'Mulish','Plus Jakarta Sans'=>'Plus Jakarta Sans','Figtree'=>'Figtree','Rubik'=>'Rubik','Karla'=>'Karla','Source Sans 3'=>'Source Sans 3','Playfair Display'=>'Playfair Display','Lora'=>'Lora','Source Serif 4'=>'Source Serif 4'];
    public static function landingFontOptions(): array { return self::LANDING_FONTS; }
    public static function landingFontKeys(): array { return array_keys(self::LANDING_FONTS); }
    public function hasCustomLandingFont(): bool { return !empty($this->landing_custom_font_name)&&!empty($this->landing_custom_font_path)&&$this->customLandingFontFormat()!==null; }
    public function customLandingFontFormat(): ?string { if(empty($this->landing_custom_font_path))return null; return match(strtolower(pathinfo($this->landing_custom_font_path,PATHINFO_EXTENSION))){'woff2'=>'woff2','woff'=>'woff','ttf'=>'truetype','otf'=>'opentype',default=>null}; }

    public const WHATSAPP_PROVIDERS=['meta_cloud']; public const DEFAULT_REMINDER_OFFSETS=[7,3,1]; public const DEFAULT_TRIAL_REMINDER_OFFSETS=[3,1]; public const REMINDER_CHANNELS=['email','sms','banner']; public const DEFAULT_REMINDER_CHANNELS=['email']; public const SMS_GATEWAYS=['twilio','infobip','termii','custom'];
    public const DEFAULT_REMINDER_SMS='{company}, your {plan} subscription on {app} expires on {date} ({days} days). Renew to avoid interruption.'; public const DEFAULT_TRIAL_SMS='{company}, your {app} free trial ends on {date} ({days} days). Subscribe to keep your workspace active.';
    public const SYSTEM_RESERVED_SUBDOMAINS=['www','admin','api','app','mail','webmail','smtp','imap','pop','pop3','ftp','sftp','ssh','ns','ns1','ns2','dns','mx','server','cpanel','whm','plesk','webdisk','autodiscover','autoconfig','panel','support','help','status','staging','dev','test','demo','beta','docs','blog','cdn','static','assets','media','files','storage','backup','auth','login','register','signup','billing','checkout','pay','payment','webhook','webhooks','tenant','tenants','central','root','system'];

    public function isSharedHosting(): bool{return $this->hosting_mode==='shared';} public function isVps(): bool{return $this->hosting_mode!=='shared';}
    public function remindersEnabled(): bool{return $this->subscription_reminders_enabled??true;} public function trialRemindersEnabled(): bool{return $this->trial_reminders_enabled??true;}
    public function reminderOffsets(): array{return $this->normalizeOffsets($this->subscription_reminder_offsets,self::DEFAULT_REMINDER_OFFSETS);} public function trialReminderOffsets(): array{return $this->normalizeOffsets($this->trial_reminder_offsets,self::DEFAULT_TRIAL_REMINDER_OFFSETS);}
    public function reminderChannels(): array{$c=$this->subscription_reminder_channels;if(!is_array($c)||empty($c))return self::DEFAULT_REMINDER_CHANNELS;return array_values(array_intersect(self::REMINDER_CHANNELS,$c));}
    private function normalizeOffsets($offsets,array $defaults): array{if(!is_array($offsets)||empty($offsets))$offsets=$defaults;$offsets=array_values(array_unique(array_filter(array_map('intval',$offsets),fn($d)=>$d>0)));rsort($offsets);return $offsets;}

    public static function instance(): self{return static::query()->firstOrCreate([],['app_name'=>'Stocky','currency_code'=>'USD','currency_symbol'=>'$']);}
    public static function currencyCode(): string{return static::instance()->currency_code??'USD';} public static function currencySymbol(): string{return static::instance()->currency_symbol??'$';}
    public static function demoDataEnabled(): bool{return (bool)(static::instance()->demo_data_enabled??false);} public static function whatsappEnabled(): bool{return (bool)(static::instance()->whatsapp_enabled??false);} public static function resolvedLandingTemplateKey(): string{return LandingPageTemplate::canonicalKey(static::instance()->landing_template);}

    public function getBankAccounts(bool $activeOnly=true): array
    {
        $details=$this->bank_details??[];
        $accounts=$details['accounts']??null;
        if(!is_array($accounts)){
            if(empty($details['bank_name'])&&empty($details['account_number'])&&empty($details['account_holder']))return [];
            $accounts=[array_merge($details,['active'=>true,'account_type'=>$details['account_type']??'savings','currency'=>$details['currency']??'HNL'])];
        }
        $accounts=array_values(array_filter($accounts,fn($a)=>is_array($a)&&(!$activeOnly||($a['active']??true))));
        usort($accounts,fn($a,$b)=>(int)($a['sort_order']??0)<=>(int)($b['sort_order']??0));
        return $accounts;
    }

    public function getBankDetails(): array
    {
        $details=$this->bank_details??[];
        $details['accounts']=$this->getBankAccounts(true);
        return $details;
    }

    public function hasBankDetails(): bool{return count($this->getBankAccounts(true))>0;}
    public function getLogoUrl(): ?string{return $this->logo_path?asset($this->logo_path):null;} public function getFaviconUrl(): ?string{return $this->favicon_path?asset($this->favicon_path):null;} public function getTenantLogoUrl(): ?string{return $this->tenant_logo_path?asset($this->tenant_logo_path):null;} public function getTenantFaviconUrl(): ?string{return $this->tenant_favicon_path?asset($this->tenant_favicon_path):null;}

    public function getTenantDefaults(): array{return ['app_name'=>$this->tenant_app_name?:($this->app_name?:'Stocky'),'company_name'=>$this->tenant_company_name?:($this->company_name?:''),'email'=>$this->tenant_email?:($this->email?:''),'phone'=>$this->tenant_phone?:($this->phone?:''),'address'=>$this->tenant_address?:($this->address?:''),'currency_code'=>$this->tenant_currency_code?:($this->currency_code?:'USD'),'currency_symbol'=>$this->tenant_currency_symbol?:($this->currency_symbol?:'$'),'default_language'=>$this->tenant_default_language?:'en','footer'=>$this->tenant_footer_text?:'','developed_by'=>$this->tenant_developed_by?:'','page_title_suffix'=>$this->tenant_page_title_suffix?:'','logo'=>$this->tenant_logo_path?:$this->logo_path,'favicon'=>$this->tenant_favicon_path?:$this->favicon_path];}
}
