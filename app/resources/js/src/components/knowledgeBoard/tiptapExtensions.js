import { Extension } from '@tiptap/core';

export const FontSize = Extension.create({
    name: 'fontSize',

    addOptions() {
        return { types: ['textStyle'] };
    },

    addGlobalAttributes() {
        return [
            {
                types: this.options.types,
                attributes: {
                    fontSize: {
                        default: null,
                        parseHTML: (element) => element.style.fontSize?.replace(/['"]+/g, '') || null,
                        renderHTML: (attributes) => {
                            if (!attributes.fontSize) {
                                return {};
                            }
                            return { style: `font-size: ${attributes.fontSize}` };
                        },
                    },
                },
            },
        ];
    },

    addCommands() {
        return {
            setFontSize: (fontSize) => ({ chain }) => chain()
                .setMark('textStyle', { fontSize })
                .run(),
            unsetFontSize: () => ({ chain }) => chain()
                .setMark('textStyle', { fontSize: null })
                .removeEmptyTextStyle()
                .run(),
        };
    },
});

export const FONT_SIZE_OPTIONS = ['12px', '14px', '16px', '18px', '20px', '24px', '28px'];

export const FONT_FAMILY_OPTIONS = [
    { label: 'Default', value: '' },
    { label: 'Serif', value: 'Georgia, serif' },
    { label: 'Mono', value: 'Consolas, monospace' },
    { label: 'Sans', value: 'Instrument Sans, sans-serif' },
];
