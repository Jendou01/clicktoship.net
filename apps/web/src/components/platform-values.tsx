"use client";
import {useState} from 'react';
import type {Locale} from '@/lib/i18n';
import {platformCopy,platformLabel,platformLabels,platformValue} from '@/lib/platform';
import {dashboardStatus} from '@/lib/dashboard';
import {DataTable,EmptyState,StatusBadge} from '@/components/ui/workspace';
import {formatMinor} from '@/lib/finance';
export function PlatformTrend({rows,locale,label,bucketLabel}:{rows:{day:string;count:number}[];locale:Locale;label:string;bucketLabel?:string}){const [open,setOpen]=useState(false),t=platformCopy[locale],max=Math.max(1,...rows.map(r=>r.count));return <div className="platform-trend"><div className="activity-chart" role="img" aria-label={`${label}: ${rows.reduce((a,b)=>a+b.count,0)}`}>{rows.map(r=><div key={r.day} className="chart-column" title={`${r.day}: ${r.count}`}><span style={{height:`${r.count?Math.max(2,r.count/max*100):0}%`}}/></div>)}</div><details onToggle={e=>setOpen(e.currentTarget.open)}><summary>{label} — {bucketLabel||t.day} / {t.count}</summary>{open&&<DataTable label={label}><thead><tr><th>{bucketLabel||t.day}</th><th>{t.count}</th></tr></thead><tbody>{rows.map(r=><tr key={r.day}><td data-label={bucketLabel||t.day}><bdi dir="ltr">{r.day}</bdi></td><td data-label={t.count}>{r.count}</td></tr>)}</tbody></DataTable>}</details></div>;}
export function PlatformValues({value,locale,label,resolve,bucketLabel}:{value:unknown;locale:Locale;label?:string;resolve?:(key:string,locale:Locale)=>string|undefined;bucketLabel?:string}){
 const t=platformCopy[locale];
 if(value===null||value===undefined)return <span>—</span>;
 if(typeof value==='boolean')return <StatusBadge>{value?t.yes:t.no}</StatusBadge>;
 if(typeof value==='number')return <bdi dir="ltr">{new Intl.NumberFormat(locale==='ar'?'ar-SA':'en-GB').format(value)}</bdi>;
 if(typeof value==='string'){
  if(/^\d{4}-\d{2}-\d{2}T|^\d{4}-\d{2}-\d{2} /.test(value)){const d=new Date(value);if(Number.isFinite(d.valueOf()))return <bdi>{new Intl.DateTimeFormat(locale==='ar'?'ar-SA-u-ca-gregory':'en-GB',{dateStyle:'medium',timeStyle:'short',timeZone:'UTC'}).format(d)}</bdi>;}
  const translated=resolve?.(value,locale)||platformValue(value,locale);const state=dashboardStatus(value,locale);const fallback=locale==='ar'?'نشاط مسجّل':'Recorded activity';return <bdi dir={translated||platformLabels[value]||state!==fallback?'auto':'ltr'}>{translated||(platformLabels[value]?platformLabel(value,locale):state!==fallback?state:value)}</bdi>;
 }
 if(Array.isArray(value)){
  if(!value.length)return <EmptyState>{t.empty}</EmptyState>;
  if(value.every(r=>typeof r==='object'&&r&&'day' in r&&'count' in r))return <PlatformTrend rows={value} locale={locale} label={label||t.value} bucketLabel={bucketLabel}/>;
  if(value.every(r=>typeof r==='object'&&r&&'status' in r&&'count' in r)){const max=Math.max(1,...value.map(r=>r.count));return <ul className="distribution-list">{value.map((r,i)=><li key={i}><PlatformValues value={r.status} locale={locale} resolve={resolve} bucketLabel={bucketLabel}/><meter aria-label={`${label||t.value} ${r.status}`} min={0} max={max} value={r.count}>{r.count}</meter><bdi>{r.count}</bdi></li>)}</ul>;}
  return <div className="platform-record-list">{value.map((row,i)=><div className="platform-record" key={i}><PlatformValues value={row} locale={locale} resolve={resolve} bucketLabel={bucketLabel}/></div>)}</div>;
 }
 const record=value as Record<string,unknown>;
 return <dl className="platform-values">{Object.entries(record).map(([key,v])=><div key={key}><dt>{resolve?.(key,locale)||platformLabel(key,locale)}</dt><dd>{['posted','reserved','available'].includes(key)&&typeof v==='string'&&typeof record.currency==='string'&&typeof record.exponent==='number'?<bdi dir="ltr">{formatMinor(v,record.currency,record.exponent)}</bdi>:<PlatformValues value={v} locale={locale} label={resolve?.(key,locale)||platformLabel(key,locale)} resolve={resolve} bucketLabel={bucketLabel}/>}</dd></div>)}</dl>;
}
