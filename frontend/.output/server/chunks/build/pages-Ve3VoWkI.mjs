import { a as useApi, b as useAuth } from '../virtual/entry.mjs';
import { N as NuxtLink } from './nuxt-link-CiqBxGRM.mjs';
import { defineComponent, ref, watch, mergeProps, unref, withCtx, createVNode, toDisplayString, openBlock, createBlock, createCommentVNode, useSSRContext } from 'vue';
import { ssrRenderAttrs, ssrRenderClass, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrInterpolate, ssrRenderComponent, ssrRenderStyle } from 'vue/server-renderer';
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

//#region app/components/LogCreateModal.vue?vue&type=script&setup=true&lang.ts
var LogCreateModal_vue_vue_type_script_setup_true_lang_default = /*@__PURE__*/ defineComponent({
	__name: "LogCreateModal",
	__ssrInlineRender: true,
	props: {
		isOpen: { type: Boolean },
		categories: {}
	},
	emits: ["close", "created"],
	setup(__props, { emit: __emit }) {
		const props = __props;
		useApi();
		const today = (/* @__PURE__ */ new Date()).toISOString().split("T")[0];
		const currentTime = (/* @__PURE__ */ new Date()).toTimeString().slice(0, 5);
		const form = ref({
			category_id: props.categories[0]?.id || null,
			activity_date: today,
			activity_time: currentTime,
			content: "",
			note: ""
		});
		const isLoading = ref(false);
		const errorMessage = ref("");
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.isOpen) {
				_push(`<div${ssrRenderAttrs(mergeProps({ class: "fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4" }, _attrs))}><div class="w-full max-w-md bg-white rounded-t-2xl sm:rounded-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto"><div class="flex justify-between items-center border-b pb-3"><h2 class="text-lg font-bold text-slate-800">アクティビティを記録</h2><button class="text-slate-400 hover:text-slate-600 text-xl font-bold"> × </button></div>`);
				if (unref(errorMessage)) _push(`<div class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">${ssrInterpolate(unref(errorMessage))}</div>`);
				else _push(`<!---->`);
				_push(`<form class="space-y-4"><div><label class="block text-xs font-semibold text-slate-600 mb-1">カテゴリ</label><select class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><!--[-->`);
				ssrRenderList(__props.categories, (cat) => {
					_push(`<option${ssrRenderAttr("value", cat.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).category_id) ? ssrLooseContain(unref(form).category_id, cat.id) : ssrLooseEqual(unref(form).category_id, cat.id)) ? " selected" : ""}>${ssrInterpolate(cat.name)}</option>`);
				});
				_push(`<!--]--></select></div><div class="grid grid-cols-2 gap-2"><div><label class="block text-xs font-semibold text-slate-600 mb-1">実施日</label><input${ssrRenderAttr("value", unref(form).activity_date)} type="date" required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">時刻（任意）</label><input${ssrRenderAttr("value", unref(form).activity_time)} type="time" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></div></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">活動内容</label><textarea required rows="3" placeholder="例: 算数のドリルを2ページ進めた" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">${ssrInterpolate(unref(form).content)}</textarea></div><div><label class="block text-xs font-semibold text-slate-600 mb-1">補足メモ（任意）</label><input${ssrRenderAttr("value", unref(form).note)} type="text" placeholder="例: つまずいた箇所あり" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></div><button type="submit"${ssrIncludeBooleanAttr(unref(isLoading)) ? " disabled" : ""} class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl shadow-xs transition disabled:opacity-50">${ssrInterpolate(unref(isLoading) ? "保存中..." : "記録を保存する")}</button></form></div></div>`);
			} else _push(`<!---->`);
		};
	}
});
//#endregion
//#region app/components/LogCreateModal.vue
var _sfc_setup$1 = LogCreateModal_vue_vue_type_script_setup_true_lang_default.setup;
LogCreateModal_vue_vue_type_script_setup_true_lang_default.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("components/LogCreateModal.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
var LogCreateModal_default = Object.assign(LogCreateModal_vue_vue_type_script_setup_true_lang_default, { __name: "LogCreateModal" });
//#endregion
//#region app/pages/index.vue?vue&type=script&setup=true&lang.ts
var index_vue_vue_type_script_setup_true_lang_default = /*@__PURE__*/ defineComponent({
	__name: "index",
	__ssrInlineRender: true,
	setup(__props) {
		const { fetchApi } = useApi();
		const { user } = useAuth();
		const logs = ref([]);
		const categories = ref([]);
		const familyMembers = ref([]);
		const isLoading = ref(false);
		const isModalOpen = ref(false);
		const filterPeriod = ref("this_week");
		const filterFrom = ref("");
		const filterTo = ref("");
		const filterUserId = ref(user.value ? String(user.value.id) : "");
		const filterCategoryId = ref("");
		const setPeriodRange = () => {
			const now = /* @__PURE__ */ new Date();
			if (filterPeriod.value === "this_week") {
				const day = now.getDay() || 7;
				const monday = new Date(now);
				monday.setDate(now.getDate() - day + 1);
				const sunday = new Date(monday);
				sunday.setDate(monday.getDate() + 6);
				filterFrom.value = monday.toISOString().slice(0, 10);
				filterTo.value = sunday.toISOString().slice(0, 10);
			} else if (filterPeriod.value === "this_month") {
				const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
				const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
				filterFrom.value = firstDay.toISOString().slice(0, 10);
				filterTo.value = lastDay.toISOString().slice(0, 10);
			}
		};
		const fetchLogs = async () => {
			isLoading.value = true;
			try {
				const params = new URLSearchParams();
				if (filterFrom.value) params.append("from", filterFrom.value);
				if (filterTo.value) params.append("to", filterTo.value);
				if (filterUserId.value) params.append("user_id", filterUserId.value);
				if (filterCategoryId.value) params.append("category_id", filterCategoryId.value);
				const res = await fetchApi(`/logs?${params.toString()}`);
				logs.value = res.data;
			} catch (err) {
				console.error("ログの取得に失敗しました", err);
			} finally {
				isLoading.value = false;
			}
		};
		watch(filterPeriod, () => {
			if (filterPeriod.value !== "custom") {
				setPeriodRange();
				fetchLogs();
			}
		});
		watch([filterUserId, filterCategoryId], () => {
			fetchLogs();
		});
		return (_ctx, _push, _parent, _attrs) => {
			const _component_NuxtLink = NuxtLink;
			const _component_LogCreateModal = LogCreateModal_default;
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "space-y-4" }, _attrs))}><div class="bg-white p-3 rounded-xl border border-slate-200 space-y-3"><div class="flex items-center justify-between"><span class="text-xs font-semibold text-slate-500">表示期間</span><div class="flex space-x-1 bg-slate-100 p-1 rounded-lg"><button class="${ssrRenderClass(["px-2.5 py-1 text-xs rounded-md font-medium transition", unref(filterPeriod) === "this_week" ? "bg-white text-blue-600 shadow-xs" : "text-slate-600"])}"> 今週 </button><button class="${ssrRenderClass(["px-2.5 py-1 text-xs rounded-md font-medium transition", unref(filterPeriod) === "this_month" ? "bg-white text-blue-600 shadow-xs" : "text-slate-600"])}"> 今月 </button><button class="${ssrRenderClass(["px-2.5 py-1 text-xs rounded-md font-medium transition", unref(filterPeriod) === "custom" ? "bg-white text-blue-600 shadow-xs" : "text-slate-600"])}"> 指定 </button></div></div>`);
			if (unref(filterPeriod) === "custom") _push(`<div class="flex items-center space-x-2 pt-2 border-t"><input${ssrRenderAttr("value", unref(filterFrom))} type="date" class="px-2 py-1 border text-xs rounded-lg w-full"><span class="text-slate-400 text-xs">〜</span><input${ssrRenderAttr("value", unref(filterTo))} type="date" class="px-2 py-1 border text-xs rounded-lg w-full"><button class="px-3 py-1 bg-slate-800 text-white text-xs rounded-lg shrink-0"> 適用 </button></div>`);
			else _push(`<!---->`);
			_push(`<div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100"><div><label class="block text-2xs font-semibold text-slate-500 mb-1">投稿者</label><select class="w-full px-2 py-1.5 text-xs border border-slate-300 rounded-lg bg-white"><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(filterUserId)) ? ssrLooseContain(unref(filterUserId), "") : ssrLooseEqual(unref(filterUserId), "")) ? " selected" : ""}>全員</option><!--[-->`);
			ssrRenderList(unref(familyMembers), (member) => {
				_push(`<option${ssrRenderAttr("value", String(member.id))}${ssrIncludeBooleanAttr(Array.isArray(unref(filterUserId)) ? ssrLooseContain(unref(filterUserId), String(member.id)) : ssrLooseEqual(unref(filterUserId), String(member.id))) ? " selected" : ""}>${ssrInterpolate(member.id === unref(user)?.id ? "自分" : member.display_name)}</option>`);
			});
			_push(`<!--]--></select></div><div><label class="block text-2xs font-semibold text-slate-500 mb-1">カテゴリ</label><select class="w-full px-2 py-1.5 text-xs border border-slate-300 rounded-lg bg-white"><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(filterCategoryId)) ? ssrLooseContain(unref(filterCategoryId), "") : ssrLooseEqual(unref(filterCategoryId), "")) ? " selected" : ""}>全カテゴリ</option><!--[-->`);
			ssrRenderList(unref(categories), (cat) => {
				_push(`<option${ssrRenderAttr("value", String(cat.id))}${ssrIncludeBooleanAttr(Array.isArray(unref(filterCategoryId)) ? ssrLooseContain(unref(filterCategoryId), String(cat.id)) : ssrLooseEqual(unref(filterCategoryId), String(cat.id))) ? " selected" : ""}>${ssrInterpolate(cat.name)}</option>`);
			});
			_push(`<!--]--></select></div></div></div><button class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl shadow-md flex items-center justify-center space-x-2 transition"><span class="text-lg font-bold">+</span><span>アクティビティを記録する</span></button>`);
			if (unref(isLoading)) _push(`<div class="text-center py-8 text-slate-400 text-sm"> 読み込み中... </div>`);
			else if (unref(logs).length === 0) _push(`<div class="text-center py-8 bg-white rounded-xl border border-dashed border-slate-300 p-6"><p class="text-slate-500 text-sm">該当する記録がありません。</p></div>`);
			else {
				_push(`<div class="space-y-3"><!--[-->`);
				ssrRenderList(unref(logs), (log) => {
					_push(ssrRenderComponent(_component_NuxtLink, {
						key: log.id,
						to: `/logs/${log.id}`,
						class: "block bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-2 relative hover:border-blue-300 transition"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="flex items-center justify-between"${_scopeId}><div class="flex items-center space-x-2"${_scopeId}><span class="px-2 py-0.5 text-xs font-semibold text-white rounded-full" style="${ssrRenderStyle({ backgroundColor: log.category?.color_code || "#3B82F6" })}"${_scopeId}>${ssrInterpolate(log.category?.name)}</span><span class="text-xs font-bold text-slate-700"${_scopeId}>${ssrInterpolate(log.user?.display_name)}</span></div><span class="text-xs text-slate-400"${_scopeId}>${ssrInterpolate(log.activity_date)} ${ssrInterpolate(log.activity_time ? log.activity_time.slice(0, 5) : "")}</span></div><p class="text-sm text-slate-800 whitespace-pre-wrap font-normal"${_scopeId}>${ssrInterpolate(log.content)}</p>`);
								if (log.note) _push(`<p class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg"${_scopeId}> メモ: ${ssrInterpolate(log.note)}</p>`);
								else _push(`<!---->`);
							} else return [
								createVNode("div", { class: "flex items-center justify-between" }, [createVNode("div", { class: "flex items-center space-x-2" }, [createVNode("span", {
									class: "px-2 py-0.5 text-xs font-semibold text-white rounded-full",
									style: { backgroundColor: log.category?.color_code || "#3B82F6" }
								}, toDisplayString(log.category?.name), 5), createVNode("span", { class: "text-xs font-bold text-slate-700" }, toDisplayString(log.user?.display_name), 1)]), createVNode("span", { class: "text-xs text-slate-400" }, toDisplayString(log.activity_date) + " " + toDisplayString(log.activity_time ? log.activity_time.slice(0, 5) : ""), 1)]),
								createVNode("p", { class: "text-sm text-slate-800 whitespace-pre-wrap font-normal" }, toDisplayString(log.content), 1),
								log.note ? (openBlock(), createBlock("p", {
									key: 0,
									class: "text-xs text-slate-500 bg-slate-50 p-2 rounded-lg"
								}, " メモ: " + toDisplayString(log.note), 1)) : createCommentVNode("", true)
							];
						}),
						_: 2
					}, _parent));
				});
				_push(`<!--]--></div>`);
			}
			_push(ssrRenderComponent(_component_LogCreateModal, {
				"is-open": unref(isModalOpen),
				categories: unref(categories),
				onClose: ($event) => isModalOpen.value = false,
				onCreated: fetchLogs
			}, null, _parent));
			_push(`</div>`);
		};
	}
});
//#endregion
//#region app/pages/index.vue
var _sfc_setup = index_vue_vue_type_script_setup_true_lang_default.setup;
index_vue_vue_type_script_setup_true_lang_default.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("pages/index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var pages_default = index_vue_vue_type_script_setup_true_lang_default;

export { pages_default as default };
//# sourceMappingURL=pages-Ve3VoWkI.mjs.map
