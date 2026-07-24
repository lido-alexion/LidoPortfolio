import React, { useCallback, useRef, useState } from 'react';
import { FONT_FAMILY_OPTIONS, FONT_SIZE_OPTIONS } from './tiptapExtensions';
import { KNOWLEDGE_IMAGE_ACCEPT, uploadKnowledgeImage } from '../../utils/knowledgeImageUpload';
import { showToast } from '../../toast';

function ToolbarButton({ active, label, onClick, children, disabled = false }) {
    return (
        <button
            type="button"
            className={['btn btn-sm', active ? 'btn-primary' : 'btn-outline-secondary'].join(' ')}
            onClick={onClick}
            aria-label={label}
            title={label}
            disabled={disabled}
        >
            {children}
        </button>
    );
}

export default function KnowledgeEditorToolbar({ editor }) {
    const fileInputRef = useRef(null);
    const [uploading, setUploading] = useState(false);

    const setLink = useCallback(() => {
        const previous = editor.getAttributes('link').href;
        const url = window.prompt('Link URL', previous || 'https://');
        if (url === null) {
            return;
        }
        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }, [editor]);

    const insertImage = useCallback(async (file) => {
        if (!file || !editor) {
            return;
        }
        setUploading(true);
        try {
            const uploaded = await uploadKnowledgeImage(file);
            editor.chain().focus().setImage({
                src: uploaded.display_url,
                alt: uploaded.original_name || 'Image',
                fullSrc: uploaded.full_url,
            }).run();
        } catch (err) {
            showToast(err?.response?.data?.message || err?.message || 'Image upload failed.', 'danger');
        } finally {
            setUploading(false);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    }, [editor]);

    const onFileChange = useCallback((event) => {
        const file = event.target.files?.[0];
        if (file) {
            insertImage(file);
        }
    }, [insertImage]);

    if (!editor) {
        return null;
    }

    return (
        <div className="lido-knowledge-editor-toolbar d-flex flex-wrap gap-1 mb-2" role="toolbar" aria-label="Formatting">
            <ToolbarButton label="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
                <strong>B</strong>
            </ToolbarButton>
            <ToolbarButton label="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
                <em>I</em>
            </ToolbarButton>
            <ToolbarButton label="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}>
                <u>U</u>
            </ToolbarButton>
            <ToolbarButton label="Strike" active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}>
                <s>S</s>
            </ToolbarButton>
            <ToolbarButton label="Heading 1" active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}>
                H1
            </ToolbarButton>
            <ToolbarButton label="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
                H2
            </ToolbarButton>
            <ToolbarButton label="Bullet list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>
                •
            </ToolbarButton>
            <ToolbarButton label="Ordered list" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
                1.
            </ToolbarButton>
            <ToolbarButton label="Checklist" active={editor.isActive('taskList')} onClick={() => editor.chain().focus().toggleTaskList().run()}>
                ☑
            </ToolbarButton>
            <ToolbarButton label="Quote" active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>
                “
            </ToolbarButton>
            <ToolbarButton label="Code" active={editor.isActive('code')} onClick={() => editor.chain().focus().toggleCode().run()}>
                {'</>'}
            </ToolbarButton>
            <ToolbarButton label="Code block" active={editor.isActive('codeBlock')} onClick={() => editor.chain().focus().toggleCodeBlock().run()}>
                {'{ }'}
            </ToolbarButton>
            <ToolbarButton label="Horizontal rule" onClick={() => editor.chain().focus().setHorizontalRule().run()}>
                ―
            </ToolbarButton>
            <ToolbarButton label="Link" active={editor.isActive('link')} onClick={setLink}>
                🔗
            </ToolbarButton>
            <ToolbarButton
                label={uploading ? 'Uploading image…' : 'Insert image'}
                onClick={() => fileInputRef.current?.click()}
                disabled={uploading}
            >
                {uploading ? '…' : '🖼'}
            </ToolbarButton>
            <input
                ref={fileInputRef}
                type="file"
                accept={KNOWLEDGE_IMAGE_ACCEPT}
                className="d-none"
                aria-hidden="true"
                tabIndex={-1}
                onChange={onFileChange}
            />
            <ToolbarButton label="Undo" onClick={() => editor.chain().focus().undo().run()}>
                ↶
            </ToolbarButton>
            <ToolbarButton label="Redo" onClick={() => editor.chain().focus().redo().run()}>
                ↷
            </ToolbarButton>

            <select
                className="form-select form-select-sm lido-knowledge-editor-select"
                aria-label="Font size"
                value={editor.getAttributes('textStyle').fontSize || ''}
                onChange={(e) => {
                    const value = e.target.value;
                    if (!value) {
                        editor.chain().focus().unsetFontSize().run();
                    } else {
                        editor.chain().focus().setFontSize(value).run();
                    }
                }}
            >
                <option value="">Size</option>
                {FONT_SIZE_OPTIONS.map((size) => (
                    <option key={size} value={size}>{size}</option>
                ))}
            </select>

            <select
                className="form-select form-select-sm lido-knowledge-editor-select"
                aria-label="Font family"
                value={editor.getAttributes('textStyle').fontFamily || ''}
                onChange={(e) => {
                    const value = e.target.value;
                    if (!value) {
                        editor.chain().focus().unsetFontFamily().run();
                    } else {
                        editor.chain().focus().setFontFamily(value).run();
                    }
                }}
            >
                {FONT_FAMILY_OPTIONS.map((option) => (
                    <option key={option.label} value={option.value}>{option.label}</option>
                ))}
            </select>

            <input
                type="color"
                className="form-control form-control-color form-control-sm"
                aria-label="Text color"
                title="Text color"
                onChange={(e) => editor.chain().focus().setColor(e.target.value).run()}
            />
            <input
                type="color"
                className="form-control form-control-color form-control-sm"
                aria-label="Highlight color"
                title="Highlight"
                defaultValue="#fff3cd"
                onChange={(e) => editor.chain().focus().toggleHighlight({ color: e.target.value }).run()}
            />
        </div>
    );
}
