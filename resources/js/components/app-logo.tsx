import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-white dark:bg-black border border-neutral-200 dark:border-neutral-800">
                <AppLogoIcon className="size-5 object-contain" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    MoneyUnify
                </span>
            </div>
        </>
    );
}
