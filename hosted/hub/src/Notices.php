<?php
namespace Dashless\Hub;
final class Notices {
    public function __construct(private Store $store,private Identity $identity) {}
    public function send(int $owner): void {
        $this->store->locked('notice:'.$owner,function()use($owner){
            $a=$this->identity->account($owner);$kind=null;$text='';$stamp=0;
            if(($a['entitlement']??'')==='grace'){$kind='payment';$stamp=$a['past_due_since'];$text='Your renewal payment needs attention. Update your payment method within seven days of the renewal date to keep your blog online.';}
            elseif($a['state']==='suspended'){$kind='suspended';$stamp=$a['suspended_at'];$text='Your blog is offline. You can recover or export your content until '.gmdate('F j, Y',$a['delete_after']).'.';}
            elseif($a['state']==='ready'){$kind='ready';$stamp=$a['ready_at']??$a['paid_at'];$text='Your blog is ready at https://'.$a['domain'].'. Open your account to connect ChatGPT.';}
            elseif($a['state']==='refunded'){$kind='refunded';$stamp=$a['paid_at'];$text='We could not finish setting up your blog. Your subscription has been canceled and your initial payment refunded. Your bank may take several days to display the refund.';}
            if(!$kind)return;
            $key=$owner.':'.$kind.':'.$stamp;$row=$this->store->get('notice',$key);if($row && $row['status']==='sent')return;
            $sent=wp_mail(get_userdata($owner)->user_email,'Dashless: '.match($kind){'ready'=>'your blog is ready','payment'=>'payment needs attention','suspended'=>'recover or export your blog',default=>'your initial payment was refunded'},$text."\n\n".Config::origin().'/account/');
            if($sent)$this->store->put('notice',$key,['kind'=>$kind],$owner,'sent',time()+90*DAY_IN_SECONDS);
        });
    }
}
