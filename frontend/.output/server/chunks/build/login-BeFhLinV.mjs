import { b as useAuth } from '../virtual/entry.mjs';
import { defineComponent, ref, mergeProps, unref, useSSRContext } from 'vue';
import { ssrRenderAttrs, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr } from 'vue/server-renderer';
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

//#endregion
//#region app/pages/login.vue?vue&type=script&setup=true&lang.ts
var login_vue_vue_type_script_setup_true_lang_default = /*@__PURE__*/ defineComponent({
	__name: "login",
	__ssrInlineRender: true,
	setup(__props) {
		useAuth();
		const loginId = ref("");
		const password = ref("");
		const errorMessage = ref("");
		const isLoading = ref(false);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "min-h-screen bg-slate-100 flex items-center justify-center p-4" }, _attrs))}><div class="w-full max-w-sm bg-white rounded-2xl shadow-md p-6 space-y-6"><div class="text-center space-y-1"><h1 class="text-2xl font-bold text-slate-800">Famie</h1><p class="text-sm text-slate-500">家族のアクティビティ記録</p></div><form class="space-y-4">`);
			if (unref(errorMessage)) _push(`<div class="p-3 bg-red-50 text-red-600 text-sm rounded-lg border border-red-200">${ssrInterpolate(unref(errorMessage))}</div>`);
			else _push(`<!---->`);
			_push(`<div><label class="block text-sm font-medium text-slate-700 mb-1">ログインID / メールアドレス</label><input${ssrRenderAttr("value", unref(loginId))} type="text" required placeholder="例: parent1" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"></div><div><label class="block text-sm font-medium text-slate-700 mb-1">パスワード</label><input${ssrRenderAttr("value", unref(password))} type="password" required class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"></div><button type="submit"${ssrIncludeBooleanAttr(unref(isLoading)) ? " disabled" : ""} class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition duration-200 disabled:opacity-50">`);
			if (unref(isLoading)) _push(`<span>ログイン中...</span>`);
			else _push(`<span>ログイン</span>`);
			_push(`</button></form></div></div>`);
		};
	}
});
//#endregion
//#region app/pages/login.vue
var _sfc_setup = login_vue_vue_type_script_setup_true_lang_default.setup;
login_vue_vue_type_script_setup_true_lang_default.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("pages/login.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var login_default = login_vue_vue_type_script_setup_true_lang_default;

export { login_default as default };
//# sourceMappingURL=login-BeFhLinV.mjs.map
