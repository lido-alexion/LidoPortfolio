import React, { useEffect, useMemo } from 'react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import { TextStyle } from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import FontFamily from '@tiptap/extension-font-family';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Placeholder from '@tiptap/extension-placeholder';
import { FontSize, KnowledgeImage } from './tiptapExtensions';
import KnowledgeEditorToolbar from './KnowledgeEditorToolbar';
import { enhanceImageHtml, uploadKnowledgeImage } from '../../utils/knowledgeImageUpload';
import { showToast } from '../../toast';

const EMPTY_DOC = { type: 'doc', content: [{ type: 'paragraph' }] };

async function insertUploadedImage(editor, file) {
    if (!editor || !file || !file.type?.startsWith('image/')) {
        return false;
    }
    try {
        const uploaded = await uploadKnowledgeImage(file);
        editor.chain().focus().setImage({
            src: uploaded.display_url,
            alt: uploaded.original_name || 'Image',
            fullSrc: uploaded.full_url,
        }).run();
        return true;
    } catch (err) {
        showToast(err?.response?.data?.message || err?.message || 'Image upload failed.', 'danger');
        return false;
    }
}

export default function KnowledgeEditor({
    initialJson,
    initialHtml,
    onChange,
    editable = true,
    showToolbar = true,
}) {
    const extensions = useMemo(() => [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
        }),
        Underline,
        Link.configure({ openOnClick: false, autolink: true }),
        TextStyle,
        Color,
        Highlight.configure({ multicolor: true }),
        FontFamily,
        FontSize,
        KnowledgeImage,
        TaskList,
        TaskItem.configure({ nested: true }),
        Placeholder.configure({ placeholder: 'Write your market notes, research, and ideas…' }),
    ], []);

    const initialContent = useMemo(() => {
        if (initialJson && typeof initialJson === 'object') {
            return initialJson;
        }
        if (initialHtml) {
            return enhanceImageHtml(initialHtml);
        }
        return EMPTY_DOC;
    }, []);

    const editor = useEditor({
        extensions,
        content: initialContent,
        editable,
        onUpdate: ({ editor: current }) => {
            onChange?.({
                content_json: current.getJSON(),
                content_html: current.getHTML(),
            });
        },
    });

    useEffect(() => {
        if (!editor) {
            return undefined;
        }

        editor.setOptions({
            editorProps: {
                handlePaste: (_view, event) => {
                    if (!editable) {
                        return false;
                    }
                    const items = Array.from(event.clipboardData?.items || []);
                    const imageItem = items.find((item) => item.type.startsWith('image/'));
                    if (!imageItem) {
                        return false;
                    }
                    const file = imageItem.getAsFile();
                    if (!file) {
                        return false;
                    }
                    event.preventDefault();
                    insertUploadedImage(editor, file);
                    return true;
                },
                handleDrop: (_view, event, _slice, moved) => {
                    if (!editable || moved) {
                        return false;
                    }
                    const file = Array.from(event.dataTransfer?.files || []).find((f) => f.type.startsWith('image/'));
                    if (!file) {
                        return false;
                    }
                    event.preventDefault();
                    insertUploadedImage(editor, file);
                    return true;
                },
            },
        });

        return undefined;
    }, [editor, editable]);

    if (!editor) {
        return <div className="text-muted small py-3">Loading editor…</div>;
    }

    return (
        <div className="lido-knowledge-editor">
            {showToolbar && editable ? <KnowledgeEditorToolbar editor={editor} /> : null}
            <EditorContent editor={editor} className="lido-knowledge-editor-content" />
        </div>
    );
}
