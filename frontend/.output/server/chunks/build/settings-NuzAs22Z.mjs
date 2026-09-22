import { b as useAuth, a as useApi } from '../virtual/entry.mjs';
import { defineComponent, ref, mergeProps, unref, useSSRContext } from 'vue';
import { ssrRenderAttrs, ssrInterpolate, ssrRenderClass, ssrRenderList, ssrRenderStyle, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseEqual } from 'vue/server-renderer';
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

//#region app/pages/settings.vue?vue&type=script&setup=true&lang.ts
var settings_vue_vue_type_script_setup_true_lang_default = /*@__PURE__*/ defineComponent({
	__name: "settings",
	__ssrInlineRender: true,
	setup(__props) {
		const { user, isParent } = useAuth();
		useApi();
		const pwdForm = ref({
			current_password: "",
			new_password: "",
			new_password_confirmation: ""
		});
		const pwdIsLoading = ref(false);
		const pwdSuccessMessage = ref("");
		const pwdErrorMessage = ref("");
		const userForm = ref({
			username: "",
			display_name: "",
			email: "",
			password: "",
			password_confirmation: "",
			role: "child"
		});
		const userIsLoading = ref(false);
		const userSuccessMessage = ref("");
		const userErrorMessage = ref("");
		const familyMembers = ref([]);
		const categories = ref([]);
		const categoryForm = ref({
			name: "",
			color_code: "#3B82F6"
		});
		const categoryIsLoading = ref(false);
		const categorySuccessMessage = ref("");
		const categoryErrorMessage = ref("");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "space-y-6" }, _attrs))}><div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-2"><h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">ログイン情報</h2><div class="flex justify-between items-center pt-1"><div><p class="text-base font-bold text-slate-800">${ssrInterpolate(unref(user)?.display_name)}</p><p class="text-xs text-slate-500">ユーザー名: ${ssrInterpolate(unref(user)?.username)}</p></div><span class="${ssrRenderClass(["px-2.5 py-1 text-xs font-semibold rounded-full", unref(isParent) ? "bg-blue-100 text-blue-700" : "bg-slate-100 text-slate-600"])}">${ssrInterpolate(unref(isParent) ? "親（管理者）" : "子供（一般）")}</span></div></div><div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-3"><h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">家族メンバー</h2><div class="divide-y divide-slate-100"><!--[-->`);
			ssrRenderList(unref(familyMembers), (member) => {
				_push(`<div class="py-2.5 flex justify-between items-center first:pt-0 last:pb-0"><div><p class="text-sm font-medium text-slate-700">${ssrInterpolate(member.display_name)}</p><p class="text-xs text-slate-400">@${ssrInterpolate(member.username)}</p></div><span class="text-xs text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">${ssrInterpolate(member.role === "parent" ? "親" : "子")}</span></div>`);
			});
			_push(`<!--]--></div></div><div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-3"><h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">カテゴリ</h2><div class="flex flex-wrap gap-2"><!--[-->`);
			ssrRenderList(unref(categories), (category) => {
				_push(`<span class="px-2 py-0.5 text-xs font-semibold text-white rounded-full" style="${ssrRenderStyle({ backgroundColor: category.color_code })}">${ssrInterpolate(category.name)}</span>`);
			});
			_push(`<!--]--></div>`);
			if (unref(isParent)) _push(`<form class="flex items-end space-x-2 pt-2 border-t border-slate-100"><div class="flex-1"><label class="block text-xs font-semibold text-slate-600 mb-1">新しいカテゴリ名</label><input${ssrRenderAttr("value", unref(categoryForm).name)} type="text" required placeholder="例: 読書" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">色</label><input${ssrRenderAttr("value", unref(categoryForm).color_code)} type="color" class="h-9 w-12 rounded-lg border border-slate-300"></div><button type="submit"${ssrIncludeBooleanAttr(unref(categoryIsLoading)) ? " disabled" : ""} class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition disabled:opacity-50"> 追加 </button></form>`);
			else _push(`<!---->`);
			if (unref(categorySuccessMessage)) _push(`<div class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">${ssrInterpolate(unref(categorySuccessMessage))}</div>`);
			else _push(`<!---->`);
			if (unref(categoryErrorMessage)) _push(`<div class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">${ssrInterpolate(unref(categoryErrorMessage))}</div>`);
			else _push(`<!---->`);
			_push(`</div>`);
			if (unref(isParent)) {
				_push(`<div class="bg-white p-4 rounded-xl border border-blue-200 shadow-2xs space-y-4"><div class="flex items-center justify-between border-b border-slate-100 pb-2"><h2 class="text-sm font-bold text-slate-800">新しい家族を追加</h2><span class="text-2xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded font-medium">管理者機能</span></div>`);
				if (unref(userSuccessMessage)) _push(`<div class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">${ssrInterpolate(unref(userSuccessMessage))}</div>`);
				else _push(`<!---->`);
				if (unref(userErrorMessage)) _push(`<div class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">${ssrInterpolate(unref(userErrorMessage))}</div>`);
				else _push(`<!---->`);
				_push(`<form class="space-y-3"><div><label class="block text-xs font-semibold text-slate-600 mb-1">表示名（名前）<span class="text-red-500">*</span></label><input${ssrRenderAttr("value", unref(userForm).display_name)} type="text" required placeholder="例: たろう" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">ログインID（ユーザー名）<span class="text-red-500">*</span></label><input${ssrRenderAttr("value", unref(userForm).username)} type="text" required placeholder="例: taro" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">メールアドレス（任意）</label><input${ssrRenderAttr("value", unref(userForm).email)} type="email" placeholder="持っていない場合は空欄でOK" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード<span class="text-red-500">*</span></label><input${ssrRenderAttr("value", unref(userForm).password)} type="password" required placeholder="6文字以上" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード（確認）<span class="text-red-500">*</span></label><input${ssrRenderAttr("value", unref(userForm).password_confirmation)} type="password" required placeholder="もう一度入力してください" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">役割（ロール）</label><div class="flex space-x-4 pt-1"><label class="flex items-center space-x-2 text-sm text-slate-700 cursor-pointer"><input${ssrIncludeBooleanAttr(ssrLooseEqual(unref(userForm).role, "child")) ? " checked" : ""} type="radio" value="child" class="text-blue-600"><span>子供（一般）</span></label><label class="flex items-center space-x-2 text-sm text-slate-700 cursor-pointer"><input${ssrIncludeBooleanAttr(ssrLooseEqual(unref(userForm).role, "parent")) ? " checked" : ""} type="radio" value="parent" class="text-blue-600"><span>親（保護者）</span></label></div></div><button type="submit"${ssrIncludeBooleanAttr(unref(userIsLoading)) ? " disabled" : ""} class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg transition disabled:opacity-50">${ssrInterpolate(unref(userIsLoading) ? "作成中..." : "アカウントを作成する")}</button></form></div>`);
			} else _push(`<!---->`);
			_push(`<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-4"><h2 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-2">パスワードの変更</h2>`);
			if (unref(pwdSuccessMessage)) _push(`<div class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">${ssrInterpolate(unref(pwdSuccessMessage))}</div>`);
			else _push(`<!---->`);
			if (unref(pwdErrorMessage)) _push(`<div class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">${ssrInterpolate(unref(pwdErrorMessage))}</div>`);
			else _push(`<!---->`);
			_push(`<form class="space-y-3"><div><label class="block text-xs font-semibold text-slate-600 mb-1">現在のパスワード</label><input${ssrRenderAttr("value", unref(pwdForm).current_password)} type="password" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード</label><input${ssrRenderAttr("value", unref(pwdForm).new_password)} type="password" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード（確認）</label><input${ssrRenderAttr("value", unref(pwdForm).new_password_confirmation)} type="password" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div><button type="submit"${ssrIncludeBooleanAttr(unref(pwdIsLoading)) ? " disabled" : ""} class="w-full py-2.5 bg-slate-800 hover:bg-slate-900 text-white font-medium text-sm rounded-lg transition disabled:opacity-50">${ssrInterpolate(unref(pwdIsLoading) ? "更新中..." : "パスワードを変更する")}</button></form></div></div>`);
		};
	}
});
//#endregion
//#region app/pages/settings.vue
var _sfc_setup = settings_vue_vue_type_script_setup_true_lang_default.setup;
settings_vue_vue_type_script_setup_true_lang_default.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("pages/settings.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var settings_default = settings_vue_vue_type_script_setup_true_lang_default;

export { settings_default as default };
//# sourceMappingURL=settings-NuzAs22Z.mjs.map
