import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon(props: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            {...props}
            src="/moneyunify-icon.png"
            alt="MoneyUnify Icon"
        />
    );
}
