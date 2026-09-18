FROM pytorch/pytorch:2.8.0-cuda12.8-cudnn9-runtime
ARG CHORD_REVISION=675a29d1fee5e1b256833bd4edabd619be43d0c7
ARG RGBX_REVISION=977e0df27d369d3e68900399f59a42b0156d4440
ARG CLEANUP_REVISION=2a190bcf693dbc1899ce03299ebcfeaa464d3195
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/worker
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
RUN git init /opt/chord && cd /opt/chord && git remote add origin https://github.com/ubisoft/ubisoft-laforge-chord.git && git fetch --depth 1 origin ${CHORD_REVISION} && git checkout FETCH_HEAD
RUN git init /opt/stabledelight && cd /opt/stabledelight && git remote add origin https://github.com/Stable-X/StableDelight.git && git fetch --depth 1 origin ${CLEANUP_REVISION} && git checkout FETCH_HEAD
RUN git init /opt/rgbx && cd /opt/rgbx && git remote add origin https://github.com/zheng95z/rgbx.git && git fetch --depth 1 origin ${RGBX_REVISION} && git checkout FETCH_HEAD
ENV PYTHONPATH=/opt/chord:/opt/stabledelight:/opt/rgbx/rgb2x \
    RGBX_REVISION=${RGBX_REVISION} CHORD_REVISION=${CHORD_REVISION} CLEANUP_REVISION=${CLEANUP_REVISION} \
    HF_HUB_OFFLINE=1 TRANSFORMERS_OFFLINE=1 PYTHONUNBUFFERED=1
RUN python -c "from chord import ChordModel; from stabledelight.system.pipeline_yoso_delight import YosoDelightPipeline; from pipeline_rgb2x import StableDiffusionAOVMatEstPipeline"
COPY worker.py image_ops.py backends.py ./
RUN useradd --create-home --uid 10001 worker && mkdir /work && chown worker:worker /work
USER worker
WORKDIR /work
ENTRYPOINT ["python", "/opt/worker/worker.py"]
