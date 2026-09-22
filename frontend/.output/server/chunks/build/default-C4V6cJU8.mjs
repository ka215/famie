import { b as useAuth } from '../virtual/entry.mjs';
import { N as NuxtLink } from './nuxt-link-CiqBxGRM.mjs';
import { defineComponent, mergeProps, unref, withCtx, createVNode, useSSRContext } from 'vue';
import { ssrRenderAttrs, ssrInterpolate, ssrRenderSlot, ssrRenderComponent } from 'vue/server-renderer';
import 'unhead/utils';
import '../routes/renderer.mjs';
import '../_/nitro.mjs';
import 'node:http';
import 'node:https';
import 'node:events';
import 'node:buffer';
import 'node:fs';
import 'node:path';
import 'node:crypto';
import 'node:url';
import 'unhead/server';
import 'unhead/legacy';
import 'unhead/plugins';
import 'nostics';
import 'vue-bundle-renderer/runtime';
import 'devalue';
import 'vue-router';

//#region app/layouts/default.vue?vue&type=script&setup=true&lang.ts
var default_vue_vue_type_script_setup_true_lang_default = /*@__PURE__*/ defineComponent({
	__name: "default",
	__ssrInlineRender: true,
	setup(__props) {
		const { user} = useAuth();
		return (_ctx, _push, _parent, _attrs) => {
			const _component_NuxtLink = NuxtLink;
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "min-h-screen bg-slate-50 pb-20" }, _attrs))}><header class="sticky top-0 z-10 bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between shadow-xs"><h1 class="text-lg font-bold text-slate-800">Famie</h1><div class="flex items-center space-x-3 text-sm"><span class="text-slate-600 font-medium">${ssrInterpolate(unref(user)?.display_name)} さん</span><button class="text-slate-400 hover:text-red-500 text-xs"> ログアウト </button></div></header><main class="max-w-md mx-auto p-4">`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</main><nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 py-2 px-6 flex justify-around items-center z-10">`);
			_push(ssrRenderComponent(_component_NuxtLink, {
				to: "/",
				class: "flex flex-col items-center text-blue-600"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<span class="text-xs font-medium"${_scopeId}>タイムライン</span>`);
					else return [createVNode("span", { class: "text-xs font-medium" }, "タイムライン")];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(_component_NuxtLink, {
				to: "/settings",
				class: "flex flex-col items-center text-slate-400 hover:text-slate-600"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<span class="text-xs font-medium"${_scopeId}>設定</span>`);
					else return [createVNode("span", { class: "text-xs font-medium" }, "設定")];
				}),
				_: 1
			}, _parent));
			_push(`</nav></div>`);
		};
	}
});
//#endregion
//#region app/layouts/default.vue
var _sfc_setup = default_vue_vue_type_script_setup_true_lang_default.setup;
default_vue_vue_type_script_setup_true_lang_default.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("layouts/default.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var default_default = default_vue_vue_type_script_setup_true_lang_default;

export { default_default as default };
//# sourceMappingURL=default-C4V6cJU8.mjs.map
