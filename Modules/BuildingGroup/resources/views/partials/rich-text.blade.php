{{--
    Shows clean rich text of a post or comment (P07-02). $html is the output
    of Posts\RichText::clean() — the only HTML printed unescaped in the
    module — so it holds paragraphs, line breaks, bold, italic, lists and
    links with an http, https or mailto address and nothing else.
--}}
<div class="group-rich-text break-words text-zinc-800 dark:text-zinc-100 [&_a]:text-primary-700 [&_a]:underline dark:[&_a]:text-primary-300 [&_ol]:list-decimal [&_ol]:ps-6 [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:ps-6" data-test="rich-text">{!! $html !!}</div>
