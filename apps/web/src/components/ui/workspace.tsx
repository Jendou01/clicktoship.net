import type { ReactNode } from "react";
export function PageHeader({eyebrow,title,description,actions}:{eyebrow?:string;title:string;description?:string;actions?:ReactNode}){return <div className="page-heading"><div>{eyebrow&&<p className="eyebrow">{eyebrow}</p>}<h1>{title}</h1>{description&&<p className="page-description">{description}</p>}</div>{actions&&<div className="page-actions">{actions}</div>}</div>;}
export function Panel({title,children,description}:{title:string;children:ReactNode;description?:string}){return <section className="workspace-panel"><h2>{title}</h2>{description&&<p className="panel-description">{description}</p>}{children}</section>;}
export function KpiCard({label,value,href}:{label:string;value:string|number;href?:string}){return <article className="kpi-card"><h3>{href?<a href={href}>{label}</a>:label}</h3><p><bdi dir="ltr">{value}</bdi></p></article>;}
export function EmptyState({children}:{children:ReactNode}){return <p className="empty-state">{children}</p>;}
export function StatusBadge({children}:{children:ReactNode}){return <span className="status-badge">{children}</span>;}
export function Notice({children}:{children:ReactNode}){return <aside role="note" className="workspace-notice">{children}</aside>;}
export function DataTable({label,children}:{label:string;children:ReactNode}){return <div className="responsive-table" role="region" aria-label={label} tabIndex={0}><table>{children}</table></div>;}
