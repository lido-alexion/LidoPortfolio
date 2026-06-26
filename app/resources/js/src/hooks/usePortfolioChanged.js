import { useEffect } from 'react';
import { PORTFOLIO_CHANGED } from '../utils/portfolioEvents';

export default function usePortfolioChanged(handler) {
    useEffect(() => {
        const onChange = () => {
            handler();
        };
        window.addEventListener(PORTFOLIO_CHANGED, onChange);
        return () => window.removeEventListener(PORTFOLIO_CHANGED, onChange);
    }, [handler]);
}
