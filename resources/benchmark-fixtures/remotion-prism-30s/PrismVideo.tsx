import React from 'react';
import {AbsoluteFill, Easing, Sequence, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';

const cyan = '#33e6ff';
const violet = '#a78bfa';
const ink = '#05060a';

const fade = (frame: number, start: number, end: number) => interpolate(frame, [start, start + 20, end - 20, end], [0, 1, 1, 0], {
  extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.bezier(0.16, 1, 0.3, 1),
});

const Grid = () => {
  const frame = useCurrentFrame();
  return <AbsoluteFill style={{
    opacity: 0.24,
    backgroundImage: `linear-gradient(${cyan}22 1px, transparent 1px), linear-gradient(90deg, ${violet}22 1px, transparent 1px)`,
    backgroundSize: '80px 80px',
    backgroundPosition: `${interpolate(frame, [0, 900], [0, 80])}px ${interpolate(frame, [0, 900], [0, 40])}px`,
    maskImage: 'radial-gradient(circle at 50% 50%, black, transparent 78%)',
  }} />;
};

const Scene = ({from, duration, eyebrow, title, copy, accent = cyan}: {from: number; duration: number; eyebrow: string; title: string; copy: string; accent?: string}) => {
  const frame = useCurrentFrame();
  const local = frame - from;
  const {fps} = useVideoConfig();
  return <Sequence from={from} durationInFrames={duration} layout="absolute-fill">
    <AbsoluteFill style={{padding: '112px 140px', justifyContent: 'center', opacity: fade(frame, from, from + duration)}}>
      <div style={{color: accent, fontFamily: 'Arial, sans-serif', fontSize: 25, fontWeight: 700, letterSpacing: 8, textTransform: 'uppercase'}}>{eyebrow}</div>
      <div style={{color: '#f7f8ff', fontFamily: 'Arial, sans-serif', fontSize: 112, fontWeight: 800, lineHeight: 0.96, letterSpacing: -6, maxWidth: 1500, marginTop: 30,
        translate: interpolate(local, [0, 35], ['0px 50px', '0px 0px'], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.bezier(0.16, 1, 0.3, 1)}),
        scale: spring({frame: local, fps, config: {damping: 180}, durationInFrames: 40}),
      }}>{title}</div>
      <div style={{height: 5, width: interpolate(local, [16, 70], [0, 680], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}), background: `linear-gradient(90deg, ${accent}, transparent)`, margin: '46px 0 32px'}} />
      <div style={{color: '#a9b0c7', fontFamily: 'Arial, sans-serif', fontSize: 38, lineHeight: 1.45, maxWidth: 1120}}>{copy}</div>
    </AbsoluteFill>
  </Sequence>;
};

const Orbit = () => {
  const frame = useCurrentFrame();
  const labels = ['PHP', 'TypeScript', 'Python', 'Providers', 'Tools', 'Telemetry'];
  return <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', opacity: fade(frame, 580, 780)}}>
    <div style={{width: 310, height: 310, borderRadius: 999, border: `2px solid ${cyan}66`, boxShadow: `0 0 90px ${cyan}22`, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontFamily: 'Arial', fontSize: 68, fontWeight: 900}}>PRISM</div>
    {labels.map((label, index) => {
      const angle = index / labels.length * Math.PI * 2 + frame / 260;
      return <div key={label} style={{position: 'absolute', left: 960 + Math.cos(angle) * 480, top: 540 + Math.sin(angle) * 300, translate: '-50% -50%', color: index % 2 ? violet : cyan, fontFamily: 'Arial', fontSize: 28, fontWeight: 800, letterSpacing: 2}}>{label}</div>;
    })}
  </AbsoluteFill>;
};

export const PrismVideo: React.FC = () => {
  const frame = useCurrentFrame();
  return <AbsoluteFill style={{background: `radial-gradient(circle at ${30 + frame / 45}% 25%, #172036 0%, ${ink} 52%, #020205 100%)`, overflow: 'hidden'}}>
    <Grid />
    <Scene from={0} duration={190} eyebrow="One interface" title="Build AI that can change providers." copy="Prism gives applications a clean, provider-agnostic surface for text, tools, structured output, images, audio, and more." />
    <Scene from={180} duration={210} eyebrow="Durable agents" title="The request ends. The work continues." copy="Prism Harness adds threads, modes, permissions, checkpoints, and subagents without turning core into an agent framework." accent={violet} />
    <Scene from={380} duration={210} eyebrow="Measured parity" title="PHP. TypeScript. Python." copy="The same behavioral contract, exercised across languages and real providers—because agreement should be measurable, not assumed." />
    <Orbit />
    <Sequence from={760} durationInFrames={140} layout="absolute-fill">
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', opacity: fade(frame, 760, 900)}}>
        <div style={{fontFamily: 'Arial', color: '#fff', fontWeight: 900, fontSize: 150, letterSpacing: -9}}>BUILD WITH PRISM</div>
        <div style={{fontFamily: 'Arial', color: cyan, fontWeight: 700, fontSize: 30, letterSpacing: 10, marginTop: 34}}>PROVIDER AGNOSTIC · LANGUAGE PARITY · AGENT READY</div>
      </AbsoluteFill>
    </Sequence>
    <div style={{position: 'absolute', left: 52, bottom: 38, color: '#6e7895', font: '600 20px Arial', letterSpacing: 4}}>PRISM-PHP.COM</div>
    <div style={{position: 'absolute', right: 52, bottom: 38, color: '#6e7895', font: '600 20px Arial'}}>{String(Math.floor(frame / 30)).padStart(2, '0')} / 30</div>
  </AbsoluteFill>;
};
