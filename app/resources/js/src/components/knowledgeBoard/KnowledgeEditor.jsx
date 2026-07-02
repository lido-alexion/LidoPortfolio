import React, { useMemo } from 'react';
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
import { FontSize } from './tiptapExtensions';
import KnowledgeEditorToolbar from './KnowledgeEditorToolbar';

const EMPTY_DOC = { type: 'doc', content: [{ type: 'paragraph' }] };

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
        TaskList,
        TaskItem.configure({ nested: true }),
        Placeholder.configure({ placeholder: 'Write your market notes, research, and ideas…' }),
    ], []);

    const editor = useEditor({
        extensions,
        content: initialJson || initialHtml || EMPTY_DOC,
        editable,
        onUpdate: ({ editor: current }) => {
            onChange?.({
                content_json: current.getJSON(),
                content_html: current.getHTML(),
            });
        },
    });

    if (!editor) {
        return <div className="text-muted small py-3">Loading editor…</div>;
    }

    return (
        <div className="lido-knowledge-editor">
            {showToolbar ? <KnowledgeEditorToolbar editor={editor} /> : null}
            <EditorContent editor={editor} className="lido-knowledge-editor-content" />
        </div>
    );
}
