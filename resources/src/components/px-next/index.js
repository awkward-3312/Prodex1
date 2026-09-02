// px-next component barrel — for LOCAL import inside the /app/_ui playground.
// These components are NOT registered globally. Import what a view needs:
//
//   import { PxButton, PxTable } from "@/components/px-next";
//
// PxSkeleton is intentionally NOT re-exported here: the playground reuses the
// existing global <px-skeleton> (resources/src/components/PxSkeleton.vue), which
// already picks up the px-next palette through the --px-* aliases in _base.scss.

export { default as PxAlert } from "./PxAlert.vue";
export { default as PxAvatar } from "./PxAvatar.vue";
export { default as PxBadge } from "./PxBadge.vue";
export { default as PxButton } from "./PxButton.vue";
export { default as PxCard } from "./PxCard.vue";
export { default as PxCheck } from "./PxCheck.vue";
export { default as PxChartFrame } from "./PxChartFrame.vue";
export { default as PxEmptyState } from "./PxEmptyState.vue";
export { default as PxEntityCell } from "./PxEntityCell.vue";
export { default as PxField } from "./PxField.vue";
export { default as PxInput } from "./PxInput.vue";
export { default as PxKebab } from "./PxKebab.vue";
export { default as PxMenu } from "./PxMenu.vue";
export { default as PxModal } from "./PxModal.vue";
export { default as PxPageHeader } from "./PxPageHeader.vue";
export { default as PxPagination } from "./PxPagination.vue";
export { default as PxSelect } from "./PxSelect.vue";
export { default as PxShell } from "./PxShell.vue";
export { default as PxShellMock } from "./PxShellMock.vue";
export { default as PxStat } from "./PxStat.vue";
export { default as PxTable } from "./PxTable.vue";
export { default as PxTabs } from "./PxTabs.vue";
export { default as PxTag } from "./PxTag.vue";
export { default as PxTextarea } from "./PxTextarea.vue";
export { default as PxToast } from "./PxToast.vue";
export { default as PxToolbar } from "./PxToolbar.vue";
