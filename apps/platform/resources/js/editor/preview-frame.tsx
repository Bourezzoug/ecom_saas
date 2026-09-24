import {
    buildViewModel,
    designTokensCss,
    render,
    resolveData,
    wrap,
    type Menu,
    type SampleCatalog,
    type SectionDefinition,
} from '@aisg/renderer';
import { useEffect, useMemo, useRef, useState } from 'react';
import type {
    EditorPage,
    EditorProject,
    EditorSection,
    FullTokens,
} from './types';

export type Device = 'desktop' | 'tablet' | 'mobile';

export const DEVICE_WIDTHS: Record<Device, string> = {
    desktop: '100%',
    tablet: '820px',
    mobile: '390px',
};

type Props = {
    project: EditorProject;
    pages: EditorPage[];
    page: EditorPage;
    header?: EditorPage;
    footer?: EditorPage;
    tokens: FullTokens | null;
    library: Record<string, SectionDefinition>;
    catalog: SampleCatalog;
    assetUrl: (file: string) => string;
    device: Device;
    selectedId: string | null;
    onSelect: (id: string) => void;
    onNavigate: (pageId: string) => void;
};

const PAGE_LINK = '#aisg-page:';

/**
 * The iframe document: section CSS + runtime, and a tiny bridge that
 *  - swaps in new HTML on "render" (re-initialising Alpine),
 *  - reports clicks on sections ("select") and on page links ("navigate"),
 *  - never lets links or forms navigate away.
 * The iframe is sandboxed without same-origin, so it cannot touch the editor.
 */
function shell(cssUrl: string, runtimeUrl: string, alpineUrl: string): string {
    return `<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="${cssUrl}">
<style id="aisg-tokens"></style>
<style>
html,body{margin:0;background:var(--e-global-color-aisgbackground,#fff)}
[data-aisg-id]{position:relative;cursor:pointer}
[data-aisg-id]:hover{outline:2px dashed rgba(99,102,241,.55);outline-offset:-2px}
[data-aisg-id].aisg-selected{outline:2px solid #6366f1;outline-offset:-2px}
[data-aisg-id].aisg-generating{opacity:.45;pointer-events:none}
[data-aisg-id].aisg-generating::after{content:"Rewriting with AI…";position:absolute;inset:auto 1rem 1rem auto;background:#6366f1;color:#fff;font:600 12px system-ui;padding:.35rem .6rem;border-radius:999px}
</style>
<script src="${runtimeUrl}"></script>
<script src="${alpineUrl}" defer></script>
</head><body><div id="aisg-root"></div>
<script>
(function(){
  var root=document.getElementById('aisg-root'), selected=null;
  function post(msg){ parent.postMessage(msg,'*'); }
  function mark(id, scroll){
    selected=id;
    root.querySelectorAll('.aisg-selected').forEach(function(el){el.classList.remove('aisg-selected')});
    if(!id) return;
    var el=root.querySelector('[data-aisg-id="'+id+'"]');
    if(el){ el.classList.add('aisg-selected'); if(scroll){ el.scrollIntoView({block:'nearest',behavior:'smooth'}); } }
  }
  window.addEventListener('message', function(e){
    var m=e.data||{};
    if(m.type==='render'){
      if(window.Alpine && window.Alpine.destroyTree){ try{ window.Alpine.destroyTree(root); }catch(_){} }
      root.innerHTML=m.html;
      if(window.Alpine && window.Alpine.initTree){ window.Alpine.initTree(root); }
      mark(selected,false);
    } else if(m.type==='tokens'){
      document.getElementById('aisg-tokens').textContent=m.css;
      var fonts=document.getElementById('aisg-fonts');
      if(m.fontsUrl && (!fonts || fonts.getAttribute('href')!==m.fontsUrl)){
        if(!fonts){ fonts=document.createElement('link'); fonts.id='aisg-fonts'; fonts.rel='stylesheet'; document.head.appendChild(fonts); }
        fonts.setAttribute('href', m.fontsUrl);
      }
    } else if(m.type==='select'){
      mark(m.id,true);
    }
  });
  document.addEventListener('click', function(e){
    var link=e.target.closest('a');
    if(link){
      e.preventDefault();
      var href=link.getAttribute('href')||'';
      if(href.indexOf('${PAGE_LINK}')===0){ post({type:'aisg:navigate', pageId: href.slice(${PAGE_LINK.length})}); return; }
    }
    var section=e.target.closest('[data-aisg-id]');
    if(section){ post({type:'aisg:select', id: section.getAttribute('data-aisg-id')}); }
  }, true);
  document.addEventListener('submit', function(e){ e.preventDefault(); }, true);
  post({type:'aisg:ready'});
})();
</script></body></html>`;
}

function googleFontsUrl(families: string[]): string | null {
    const unique = [...new Set(families.filter(Boolean))];

    if (unique.length === 0) {
        return null;
    }

    return `https://fonts.googleapis.com/css2?${unique
        .map((f) => `family=${f.replace(/ /g, '+')}:wght@400;600;700`)
        .join('&')}&display=swap`;
}

export default function PreviewFrame({
    project,
    pages,
    page,
    header,
    footer,
    tokens,
    library,
    catalog,
    assetUrl,
    device,
    selectedId,
    onSelect,
    onNavigate,
}: Props) {
    const iframe = useRef<HTMLIFrameElement>(null);
    const [ready, setReady] = useState(false);

    const srcDoc = useMemo(
        () =>
            shell(
                assetUrl('sections.css'),
                assetUrl('aisg.js'),
                assetUrl('alpine.min.js'),
            ),
        [assetUrl],
    );

    const site = useMemo(
        () => ({
            name: project.name,
            lang: project.language,
            dir: project.direction,
            currency: project.currency ?? '',
            year: new Date().getFullYear(),
        }),
        [project],
    );

    // Page links inside the preview switch the editor's current page.
    const contentPages = useMemo(
        () => pages.filter((p) => p.kind === 'page'),
        [pages],
    );

    const menu = useMemo<Menu>(() => {
        const home = contentPages.find((p) => p.isHomepage);

        return {
            items: contentPages.map((p) => ({
                label: p.title,
                href: PAGE_LINK + p.id,
            })),
            home_href: home ? PAGE_LINK + home.id : '#',
            cart_href: '#',
        };
    }, [contentPages]);

    const html = useMemo(() => {
        const context = {
            site,
            resolveLink: (target: { kind: string; value: string | null }) => {
                if (target.kind === 'page') {
                    const match = contentPages.find(
                        (p) => p.slug === target.value,
                    );

                    return match ? PAGE_LINK + match.id : null;
                }

                if (target.kind === 'system' && target.value === 'home') {
                    return menu.home_href;
                }

                return null;
            },
        };

        const renderOne = (section: EditorSection) => {
            const definition = library[section.key];

            if (!definition) {
                return '';
            }

            const data = resolveData(definition, section.content, {
                menu,
                catalog,
            });
            const viewModel = buildViewModel(
                definition,
                section.id,
                section.content,
                section.style,
                data,
                context,
            );
            const cls =
                section.status === 'generating'
                    ? ' class="aisg-generating"'
                    : '';

            return `<div data-aisg-id="${section.id}"${cls}>${render(definition, viewModel)}</div>`;
        };

        const sections = [
            ...(header?.sections ?? []),
            ...page.sections,
            ...(footer?.sections ?? []),
        ];

        return wrap(sections.map(renderOne).join(''), site);
    }, [site, contentPages, menu, catalog, library, header, page, footer]);

    const tokensMessage = useMemo(
        () => ({
            type: 'tokens',
            css: tokens ? designTokensCss(tokens) : '',
            fontsUrl: googleFontsUrl([
                tokens?.fonts.heading.family ?? '',
                tokens?.fonts.body.family ?? '',
            ]),
        }),
        [tokens],
    );

    useEffect(() => {
        const listener = (event: MessageEvent) => {
            if (event.source !== iframe.current?.contentWindow) {
                return;
            }

            const message = event.data as {
                type?: string;
                id?: string;
                pageId?: string;
            };

            if (message.type === 'aisg:ready') {
                setReady(true);
            } else if (message.type === 'aisg:select' && message.id) {
                onSelect(message.id);
            } else if (message.type === 'aisg:navigate' && message.pageId) {
                onNavigate(message.pageId);
            }
        };

        window.addEventListener('message', listener);

        return () => window.removeEventListener('message', listener);
    }, [onSelect, onNavigate]);

    const post = (message: unknown) =>
        iframe.current?.contentWindow?.postMessage(message, '*');

    useEffect(() => {
        if (ready) {
            post(tokensMessage);
        }
    }, [ready, tokensMessage]);

    useEffect(() => {
        if (ready) {
            post({ type: 'render', html });
        }
    }, [ready, html]);

    useEffect(() => {
        if (ready) {
            post({ type: 'select', id: selectedId });
        }
    }, [ready, selectedId]);

    return (
        <div className="flex h-full justify-center overflow-auto bg-muted/50 p-3">
            <iframe
                ref={iframe}
                title="Store preview"
                srcDoc={srcDoc}
                sandbox="allow-scripts"
                className="h-full rounded-md border bg-white shadow-sm transition-[width] duration-300"
                style={{ width: DEVICE_WIDTHS[device], maxWidth: '100%' }}
            />
        </div>
    );
}
