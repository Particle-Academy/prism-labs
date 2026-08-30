import {Composition, registerRoot} from 'remotion';
import {PrismVideo} from './PrismVideo';

const Root = () => (
  <Composition
    id="Prism30"
    component={PrismVideo}
    durationInFrames={900}
    fps={30}
    width={1920}
    height={1080}
  />
);

registerRoot(Root);
