import { u as useRoute$1, a as useApi, b as useAuth } from '../virtual/entry.mjs';
import { N as NuxtLink } from './nuxt-link-CiqBxGRM.mjs';
import { defineComponent, ref, computed, mergeProps, withCtx, createTextVNode, unref, useSSRContext } from 'vue';
import { ssrRenderAttrs, ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderStyle } from 'vue/server-renderer';
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

//#region app/pages/logs/[id].vue?vue&type=script&setup=true&lang.ts
var _id__vue_vue_type_script_setup_true_lang_default = /*@__PURE__*/ defineComponent({
	__name: "[id]",
	__ssrInlineRender: true,
	setup(__props) {
		useRoute$1();
		useApi();
		const { user, isParent } = useAuth();
		const log = ref(null);
		const categories = ref([]);
		const isLoading = ref(true);
		const isSaving = ref(false);
		const isDeleting = ref(false);
		const errorMessage = ref("");
		const form = ref({
			category_id: 0,
			activity_date: "",
			activity_time: "",
			content: "",
			note: ""
		});
		const canEdit = computed(() => {
			if (!log.value || !user.value) return false;
			return isParent.value || log.value.user_id === user.value.id;
		});
		return (_ctx, _push, _parent, _attrs) => {
			const _component_NuxtLink = NuxtLink;
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "space-y-4" }, _attrs))}>`);
			_push(ssrRenderComponent(_component_NuxtLink, {
				to: "/",
				class: "inline-flex items-center text-xs text-slate-500 hover:text-slate-700"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(` ← タイムラインに戻る `);
					else return [createTextVNode(" ← タイムラインに戻る ")];
				}),
				_: 1
			}, _parent));
			if (unref(isLoading)) _push(`<div class="text-center py-8 text-slate-400 text-sm"> 読み込み中... </div>`);
			else if (!unref(log)) _push(`<div class="text-center py-8 bg-white rounded-xl border border-dashed border-slate-300 p-6"><p class="text-slate-500 text-sm">${ssrInterpolate(unref(errorMessage) || "ログが見つかりません。")}</p></div>`);
			else {
				_push(`<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-4"><div class="flex items-center justify-between"><span class="text-xs font-bold text-slate-700">${ssrInterpolate(unref(log).user?.display_name)}</span><span class="text-xs text-slate-400">${ssrInterpolate(unref(log).activity_date)}</span></div>`);
				if (unref(errorMessage)) _push(`<div class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">${ssrInterpolate(unref(errorMessage))}</div>`);
				else _push(`<!---->`);
				if (unref(canEdit)) {
					_push(`<form class="space-y-4"><div><label class="block text-xs font-semibold text-slate-600 mb-1">カテゴリ</label><select class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><!--[-->`);
					ssrRenderList(unref(categories), (cat) => {
						_push(`<option${ssrRenderAttr("value", cat.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).category_id) ? ssrLooseContain(unref(form).category_id, cat.id) : ssrLooseEqual(unref(form).category_id, cat.id)) ? " selected" : ""}>${ssrInterpolate(cat.name)}</option>`);
					});
					_push(`<!--]--></select></div><div class="grid grid-cols-2 gap-2"><div><label class="block text-xs font-semibold text-slate-600 mb-1">実施日</label><input${ssrRenderAttr("value", unref(form).activity_date)} type="date" required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">時刻（任意）</label><input${ssrRenderAttr("value", unref(form).activity_time)} type="time" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></div></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">活動内容</label><textarea required rows="3" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">${ssrInterpolate(unref(form).content)}</textarea></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">補足メモ（任意）</label><input${ssrRenderAttr("value", unref(form).note)} type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div class="flex space-x-2 pt-2"><button type="submit"${ssrIncludeBooleanAttr(unref(isSaving)) ? " disabled" : ""} class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg transition disabled:opacity-50">${ssrInterpolate(unref(isSaving) ? "保存中..." : "更新する")}</button><button type="button"${ssrIncludeBooleanAttr(unref(isDeleting)) ? " disabled" : ""} class="px-4 py-2.5 bg-red-50 hover:bg-red-100 text-red-600 font-medium text-sm rounded-lg transition disabled:opacity-50"> 削除 </button></div></form>`);
				} else {
					_push(`<div class="space-y-3"><span class="inline-block px-2 py-0.5 text-xs font-semibold text-white rounded-full" style="${ssrRenderStyle({ backgroundColor: unref(log).category?.color_code || "#3B82F6" })}">${ssrInterpolate(unref(log).category?.name)}</span><p class="text-sm text-slate-800 whitespace-pre-wrap">${ssrInterpolate(unref(log).content)}</p>`);
					if (unref(log).note) _push(`<p class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg"> メモ: ${ssrInterpolate(unref(log).note)}</p>`);
					else _push(`<!---->`);
					_push(`<p class="text-xs text-slate-400">この記録は編集できません。</p></div>`);
				}
				_push(`</div>`);
			}
			_push(`</div>`);
		};
	}
});
//#endregion
//#region app/pages/logs/[id].vue
var _sfc_setup = _id__vue_vue_type_script_setup_true_lang_default.setup;
_id__vue_vue_type_script_setup_true_lang_default.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("pages/logs/[id].vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var _id__default = _id__vue_vue_type_script_setup_true_lang_default;

export { _id__default as default };
//# sourceMappingURL=_id_-Cp91PDGt.mjs.map
