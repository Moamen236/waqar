import { useTranslation } from '../lib/useTranslation';
import type { OrderTimelineData } from '../types';

/**
 * The real five-stage customer timeline (Received → Processing →
 * Shipping → Out for Delivery → Delivered) that replaces
 * order-tracking.html's generic progress bar, plus the off-line states
 * — Postponed, Backordered, Cancelled, Returned (Section 03, Section 17's
 * note on this page).
 */
export default function Timeline({ timeline }: { timeline: OrderTimelineData }) {
    const { t } = useTranslation();

    return (
        <div className="order-timeline">
            {timeline.off_track && (
                <div className="caption1 bg-surface border border-line rounded-lg px-5 py-3 mb-6">
                    {t('tracking.offTrack')} <strong className="text-black">{t(`status.${timeline.state}`)}</strong>.
                </div>
            )}
            <div className="flex items-start justify-between gap-2">
                {timeline.stages.map((stage, index) => (
                    <div key={stage.label} className="flex-1 flex flex-col items-center text-center">
                        <div className="flex items-center w-full">
                            <div
                                className={`h-0.5 flex-1 ${index === 0 ? 'opacity-0' : stage.reached ? 'bg-black' : 'bg-line'}`}
                            ></div>
                            <div
                                className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 border ${
                                    stage.reached
                                        ? 'bg-black text-white border-black'
                                        : 'bg-white text-secondary2 border-line'
                                }`}
                            >
                                <i className={`ph${stage.reached ? '-fill' : ''} ph-check text-sm`}></i>
                            </div>
                            <div
                                className={`h-0.5 flex-1 ${
                                    index === timeline.stages.length - 1
                                        ? 'opacity-0'
                                        : timeline.stages[index + 1].reached
                                          ? 'bg-black'
                                          : 'bg-line'
                                }`}
                            ></div>
                        </div>
                        <div
                            className={`caption1 mt-2 ${stage.current ? 'text-black font-semibold' : 'text-secondary'}`}
                        >
                            {t(`status.${stage.label}`)}
                        </div>
                    </div>
                ))}
            </div>

            {timeline.history.length > 0 && (
                <div className="history mt-8">
                    <div className="heading6">{t('tracking.history')}</div>
                    {timeline.history.map((entry, index) => (
                        <div key={index} className="flex items-center justify-between py-3 border-b border-line">
                            <div className="text-title">{t(`status.${entry.status}`)}</div>
                            <div className="caption1 text-secondary">{entry.at}</div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
