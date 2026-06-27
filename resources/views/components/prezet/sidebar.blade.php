{{-- Mobile Sidebar --}}
<div
    x-show="showSidebar"
    x-trap.inert.noscroll="showSidebar"
    class="fixed inset-0 z-40 flex h-full items-start overflow-y-auto bg-gray-900/50 pr-10 backdrop-blur-sm lg:hidden"
>
    <div
        class="min-h-full w-full max-w-xs bg-white px-4 pt-24 pb-12 sm:px-6"
        x-on:click.outside="showSidebar = false"
    >
        <x-prezet.nav :nav="$nav" />
    </div>
</div>

{{-- Desktop Sidebar --}}
<div class="hidden lg:relative lg:block lg:flex-none">
    <div class="absolute inset-y-0 right-0 w-[50vw] bg-gray-50"></div>
    <div
        class="absolute top-16 right-0 bottom-0 hidden h-12 w-px bg-linear-to-t from-gray-800"
    ></div>
    <div class="absolute top-28 right-0 bottom-0 hidden w-px bg-gray-800"></div>
    <div
        class="sticky top-[4.75rem] -ml-0.5 flex h-[calc(100vh-4.75rem)] w-64 flex-col justify-between overflow-x-hidden overflow-y-auto pt-16 pr-8 pb-4 pl-0.5 xl:w-72 xl:pr-16"
    >
        <x-prezet.nav :nav="$nav" />
        <div class="mt-16 text-xs text-gray-400">
            <a
                target="_blank"
                rel="noopener"
                href="https://github.com/blessedjasonmwanza/MoneyUnify"
                class="hover:text-gray-600"
            >
                Powered by MoneyUnify
            </a>
        </div>
    </div>
</div>
