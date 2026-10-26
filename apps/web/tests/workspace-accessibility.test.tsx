import {render,screen} from '@testing-library/react';
import {expect,it} from 'vitest';
import {DataTable} from '@/components/ui/workspace';
it.each(['Shipment report','تقرير الشحنات'])('names the actual table for assistive navigation: %s',label=>{render(<DataTable label={label}><thead><tr><th>Reference</th></tr></thead><tbody><tr><td>synthetic</td></tr></tbody></DataTable>);expect(screen.getByRole('table',{name:label})).toBeVisible();expect(screen.getByRole('region',{name:label})).toBeVisible();});
