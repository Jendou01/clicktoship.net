import {analyticsContext,analyticsScreens} from '@/lib/analytics';
import type {Locale} from '@/lib/i18n';
import {commerceContext,commerceScreens} from '@/lib/commerce';
import {dashboardQuery} from '@/lib/dashboard';
export function localeHref(locale:Locale,screen:string,search=''){
 if(analyticsScreens.includes(screen as typeof analyticsScreens[number]))return analyticsContext(locale,screen,search);
 if(commerceScreens.includes(screen as typeof commerceScreens[number]))return commerceContext(locale,screen,search);
 if(screen==='dashboard'||screen.startsWith('platform-')){const q=dashboardQuery(search);if(screen==='platform-merchant'){const input=new URLSearchParams(search);const id=input.get('merchant');if(id&&/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(id))q.set('merchant',id);const section=input.get('section');if(section&&['merchant','shipping','finance','pricing'].includes(section))q.set('section',section);}return `/${locale}/${screen}${q.toString()?`?${q}`:''}`;}
 const params=new URLSearchParams(search),batch=params.get('batch');if(batch&&/^[0-9a-f-]{36}$/i.test(batch)&&['bulk','bulk-import','bulk-results','bulk-history'].includes(screen))return `/${locale}/${screen}?batch=${encodeURIComponent(batch)}`;
 const id=params.get('shipment');return `/${locale}/${screen}${id&&/^[0-9a-f-]{36}$/i.test(id)&&['shipment-details','shipment-create','shipment-documents','tracking','tracking-details','tracking-exceptions','international','customs-declaration','customs-products','commercial-invoice','packing-list','customs-readiness','customs-documents','international-history'].includes(screen)?`?shipment=${encodeURIComponent(id)}`:''}`;
}
